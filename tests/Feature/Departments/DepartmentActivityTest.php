<?php

namespace Tests\Feature\Departments;

use App\Enums\Level;
use App\Enums\Programme;
use App\Enums\Role;
use App\Enums\VoterRole;
use App\Models\AuditLog;
use App\Models\Election;
use App\Models\Organization;
use App\Models\OrganizationActivity;
use App\Models\OrganizationRollEntry;
use App\Models\User;
use App\Models\Voter;
use App\Support\Departments\ActivityBackfill;
use App\Support\Elections\ElectionLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Each department's activity log (docs/department-elections.md): who
 * registered, signed in, voted (never how) and changed things, kept apart
 * from NACOS's audit log and from other departments.
 */
class DepartmentActivityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $fst;

    private Voter $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fst = Organization::create(['name' => 'Food Science and Technology', 'short_name' => 'FST', 'slug' => 'food-science']);
        $this->admin = $this->voter($this->fst, ['fullname' => 'Ada Admin', 'email' => 'ada@fst.test', 'matric_number' => null], VoterRole::Admin);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function voter(Organization $organization, array $attributes = [], VoterRole $role = VoterRole::Voter): Voter
    {
        $voter = new Voter(['fullname' => 'Tolu Student', 'matric_number' => 'F/ND/24/3001001', 'password' => 'Password1!', ...$attributes]);
        $voter->organization_id = $organization->id;
        $voter->role = $role;
        $voter->save();

        return $voter;
    }

    private function onRoll(string $matric): void
    {
        OrganizationRollEntry::create(['organization_id' => $this->fst->id, 'matric_number' => $matric, 'fullname' => 'Someone', 'level' => Level::ND1, 'programme' => Programme::FullTime]);
    }

    private function openElection(): Election
    {
        $election = new Election(['title' => 'FST Primaries', 'roll_only' => true]);
        $election->organization_id = $this->fst->id;
        $election->save();
        $position = $election->positions()->create(['title' => 'President', 'sort_order' => 0]);
        $position->candidates()->create(['name' => 'Bola', 'sort_order' => 0]);
        $position->candidates()->create(['name' => 'Chidi', 'sort_order' => 1]);
        ElectionLifecycle::launch($election, 6);

        return $election->refresh();
    }

    /**
     * @return list<string>
     */
    private function actions(): array
    {
        return $this->fst->activity()->orderBy('id')->pluck('action')->map(fn ($action) => (string) $action)->all();
    }

    #[Test]
    public function a_students_registration_sign_ins_and_vote_are_logged_but_never_their_choices(): void
    {
        $this->onRoll('F/ND/24/3001001');
        $this->onRoll('F/ND/24/3001002');
        $early = $this->voter($this->fst, ['matric_number' => 'F/ND/24/3001002', 'fullname' => 'Early Voter']);
        $election = $this->openElection();

        $this->post(route('vote.register.store', $this->fst), [
            'fullname' => 'Tolu Student', 'matric_number' => 'F/ND/24/3001001', 'password' => 'Password1!', 'password_confirmation' => 'Password1!',
        ])->assertRedirect();
        $this->post(route('vote.logout', $this->fst));

        $this->post(route('vote.login.store', $this->fst), ['login' => 'F/ND/24/3001002', 'password' => 'wrong'])->assertSessionHasErrors('login');
        $this->post(route('vote.login.store', $this->fst), ['login' => 'F/ND/24/3009999', 'password' => 'wrong'])->assertSessionHasErrors('login');
        $this->post(route('vote.login.store', $this->fst), ['login' => 'F/ND/24/3001002', 'password' => 'Password1!'])->assertRedirect();

        $position = $election->positions()->firstOrFail();
        $chidi = $position->candidates()->where('name', 'Chidi')->firstOrFail();
        $this->post(route('vote.elections.vote', [$this->fst, $election]), ['choices' => [$position->id => $chidi->id]])->assertRedirect();

        $this->assertSame(
            ['election_launched', 'voter_registered', 'logout', 'login_failed', 'login_failed', 'login', 'election_voted'],
            $this->actions(),
        );

        $registered = $this->fst->activity()->where('action', 'voter_registered')->firstOrFail();
        $this->assertSame('voter', $registered->actor_type);
        $this->assertSame('F/ND/24/3001001', $registered->actor_login);
        $this->assertSame('127.0.0.1', $registered->ip_address);

        $failed = $this->fst->activity()->where('action', 'login_failed')->orderBy('id')->get();
        $this->assertSame($early->id, $failed[0]->voter_id);
        $this->assertNull($failed[1]->voter_id);
        $this->assertSame('F/ND/24/3009999', $failed[1]->meta['login'] ?? null);

        $voted = $this->fst->activity()->where('action', 'election_voted')->firstOrFail();
        $this->assertSame($early->id, $voted->voter_id);
        $this->assertSame($election->id, $voted->subject_id);
        // That they voted, never how.
        $this->assertStringNotContainsString('Chidi', (string) json_encode($voted->getAttributes()));
        $this->assertStringNotContainsString((string) $chidi->id, (string) json_encode($voted->meta));

        // None of it goes into NACOS's audit log.
        $this->assertSame(0, AuditLog::query()->count());
    }

    #[Test]
    public function the_admins_work_is_logged_as_them_and_a_nacos_admins_as_nacos(): void
    {
        $this->actingAs($this->admin, 'voter');

        $csv = UploadedFile::fake()->createWithContent('nd1.csv', "Matric number,Full name\nF/ND/24/3001001,OKAFOR Tolu\n");
        $this->post(route('vote.admin.roll.store', $this->fst), ['programme' => 'full_time', 'level' => 'ND1', 'roll' => $csv])->assertRedirect();
        $this->post(route('vote.admin.elections.store', $this->fst), ['title' => 'FST Elections'])->assertRedirect();
        $election = $this->fst->elections()->firstOrFail();
        $this->post(route('vote.admin.elections.positions.store', [$this->fst, $election]), ['title' => 'President'])->assertRedirect();

        $student = $this->voter($this->fst);
        $this->post(route('vote.admin.voters.password', [$this->fst, $student]))->assertRedirect();
        $this->put(route('vote.admin.voters.status', [$this->fst, $student]), ['status' => 'suspended'])->assertRedirect();

        $this->assertSame(['roll_imported', 'election_created', 'election_position_added', 'voter_password_slip', 'voter_suspended'], $this->actions());
        $this->assertSame(5, $this->fst->activity()->where('voter_id', $this->admin->id)->where('actor_name', 'Ada Admin')->count());
        $this->assertSame('ND1 Full-time', $this->fst->activity()->where('action', 'roll_imported')->value('meta')['class'] ?? null);

        // A NACOS admin doing the work for them.
        auth('voter')->logout();
        $nacosAdmin = User::factory()->role(Role::Admin)->create(['fullname' => 'Nacos Boss']);
        $this->actingAs($nacosAdmin, 'web');
        $this->delete(route('vote.admin.elections.destroy', [$this->fst, $election]))->assertRedirect();
        $this->post(route('admin.departments.admins.password', [$this->fst, $this->admin]))->assertRedirect();

        $byNacos = $this->fst->activity()->where('actor_type', 'nacos')->orderBy('id')->get();
        $this->assertSame(['election_deleted', 'admin_password'], $byNacos->pluck('action')->all());
        $this->assertSame($nacosAdmin->id, $byNacos[0]->user_id);
        $this->assertSame('Nacos Boss', $byNacos[0]->actor_name);

        // NACOS's audit log keeps only NACOS's own record of its admin's work.
        $this->assertSame(['vote_space_admin_password'], AuditLog::query()->pluck('action')->all());
    }

    #[Test]
    public function the_scheduler_closing_voting_is_logged_as_the_system(): void
    {
        $election = $this->openElection();
        $election->forceFill(['ends_at' => now()->subMinute()])->save();

        $this->artisan('elections:close')->assertSuccessful();

        $closed = $this->fst->activity()->where('action', 'election_closed')->firstOrFail();
        $this->assertNull($closed->actor_type);
        $this->assertTrue($closed->meta['automatic'] ?? false);
        $this->assertSame(0, AuditLog::query()->count());
    }

    #[Test]
    public function admins_see_and_export_their_departments_activity_only(): void
    {
        $other = Organization::create(['name' => 'Mass Communication', 'slug' => 'mass-comm']);
        $outsider = $this->voter($other, ['fullname' => 'Outside Person', 'matric_number' => 'F/ND/24/3007777']);
        $this->post(route('vote.login.store', $other), ['login' => 'F/ND/24/3007777', 'password' => 'Password1!']);
        auth('voter')->logout();

        $student = $this->voter($this->fst, ['fullname' => 'Tolu Student']);
        $this->post(route('vote.login.store', $this->fst), ['login' => 'F/ND/24/3001001', 'password' => 'Password1!']);

        // A student can't see it.
        $this->get(route('vote.admin.activity', $this->fst))->assertForbidden();
        auth('voter')->logout();

        $this->actingAs($this->admin, 'voter')
            ->get(route('vote.admin.activity', $this->fst))
            ->assertOk()
            ->assertSee('Tolu Student')
            ->assertSee('Logged in')
            ->assertDontSee('Outside Person');

        $this->get(route('vote.admin.activity', [$this->fst, 'who' => 'nobody-like-this']))->assertOk()->assertSee('Nothing found');

        $csv = $this->get(route('vote.admin.activity.export', $this->fst))->assertOk()->streamedContent();
        $this->assertStringContainsString('Tolu Student', $csv);
        $this->assertStringContainsString('F/ND/24/3001001', $csv);
        $this->assertStringNotContainsString('Outside Person', $csv);
        $this->assertSame(1, $this->fst->activity()->where('action', 'activity_exported')->count());

        // NACOS admins reach it from Admin → Departments.
        auth('voter')->logout();
        $this->actingAs(User::factory()->role(Role::Admin)->create(), 'web');
        $this->get(route('admin.departments.show', $this->fst))->assertSee(route('vote.admin.activity', $this->fst));
        $this->get(route('vote.admin.activity', $this->fst))->assertOk()->assertSee('Tolu Student');

        $this->assertSame($outsider->id, $other->activity()->value('voter_id'));
        $this->assertSame($student->id, $this->fst->activity()->where('action', 'login')->value('voter_id'));
    }

    #[Test]
    public function what_happened_before_the_log_existed_is_filled_in_from_its_records(): void
    {
        $this->onRoll('F/ND/24/3001001');
        $voter = $this->voter($this->fst);
        $voter->forceFill(['created_at' => now()->subDays(3)])->save();
        $election = $this->openElection();
        DB::table('organization_election_voters')->insert(['election_id' => $election->id, 'voter_id' => $voter->id, 'voted_at' => now()]);
        ElectionLifecycle::close($election);

        // As it was before the log existed.
        OrganizationActivity::query()->delete();
        ActivityBackfill::run();

        $this->assertSame(['voter_registered', 'election_launched', 'election_voted', 'election_closed'], $this->fst->activity()->orderBy('created_at')->orderBy('id')->pluck('action')->all());
        $registered = $this->fst->activity()->where('action', 'voter_registered')->firstOrFail();
        $this->assertSame($voter->id, $registered->voter_id);
        $this->assertTrue($registered->meta['recorded_later'] ?? false);
        $this->assertSame($voter->created_at->toDateTimeString(), $registered->created_at->toDateTimeString());
        // Admins' accounts aren't registrations.
        $this->assertSame(0, OrganizationActivity::query()->where('voter_id', $this->admin->id)->count());
    }
}
