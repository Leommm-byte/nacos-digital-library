<?php

namespace Tests\Feature\Departments;

use App\Enums\ElectionStatus;
use App\Enums\Level;
use App\Enums\Programme;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Enums\VoterRole;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\Organization;
use App\Models\OrganizationRollEntry;
use App\Models\User;
use App\Models\Voter;
use App\Support\Elections\ElectionLifecycle;
use App\Support\Elections\LiveResults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Departments' election spaces (docs/department-elections.md): set up by a
 * NACOS admin, run by the department's own admins, kept apart from the
 * NACOS app and from each other.
 */
class DepartmentElectionsTest extends TestCase
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
        $voter = new Voter([
            'fullname' => 'Tolu Student',
            'matric_number' => 'F/ND/24/3001001',
            'password' => 'Password1!',
            ...$attributes,
        ]);
        $voter->organization_id = $organization->id;
        $voter->role = $role;
        $voter->save();

        return $voter;
    }

    private function onRoll(Organization $organization, string $matric, Level $level = Level::ND1, Programme $programme = Programme::FullTime): OrganizationRollEntry
    {
        return OrganizationRollEntry::create([
            'organization_id' => $organization->id,
            'matric_number' => $matric,
            'fullname' => 'Someone',
            'level' => $level,
            'programme' => $programme,
        ]);
    }

    /**
     * An open election of the department, with one position and two candidates.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function openElection(Organization $organization, array $attributes = []): Election
    {
        $election = new Election(['title' => 'FST Primaries', 'roll_only' => true, ...$attributes]);
        $election->organization_id = $organization->id;
        $election->save();
        $position = $election->positions()->create(['title' => 'President', 'sort_order' => 0]);
        $position->candidates()->create(['name' => 'Bola', 'sort_order' => 0]);
        $position->candidates()->create(['name' => 'Chidi', 'sort_order' => 1]);
        ElectionLifecycle::launch($election, 6);

        return $election->refresh();
    }

    #[Test]
    public function a_nacos_admin_sets_up_a_department_and_its_first_admin_signs_in_with_the_slip(): void
    {
        $nacosAdmin = User::factory()->role(Role::Admin)->create();

        $this->actingAs($nacosAdmin)
            ->post(route('admin.departments.store'), ['name' => 'Mass Communication', 'short_name' => 'MAC', 'contact_name' => 'Mrs Ade'])
            ->assertRedirect(route('admin.departments.show', 'mass-communication'));

        $department = Organization::where('slug', 'mass-communication')->firstOrFail();
        $this->assertTrue($department->isActive());

        $this->post(route('admin.departments.admins.store', $department), ['fullname' => 'Kemi Okafor', 'email' => 'KEMI@mac.test'])
            ->assertSessionHas('slip', fn (array $slip) => $slip['login'] === 'kemi@mac.test');
        $password = session('slip')['password'];

        $this->get(route('admin.departments.show', $department))->assertSee('Kemi Okafor')->assertSee($password);

        // The slip works in the department's space, and asks for a new password first.
        auth('web')->logout();
        $this->post(route('vote.login.store', $department), ['login' => 'kemi@mac.test', 'password' => $password])
            ->assertRedirect(route('vote.password.edit', $department));
        $this->get(route('vote.admin.elections', $department))->assertRedirect(route('vote.password.edit', $department));

        $this->put(route('vote.password.update', $department), ['password' => 'MyOwnPass9', 'password_confirmation' => 'MyOwnPass9'])
            ->assertRedirect(route('vote.home', $department));
        $this->get(route('vote.admin.elections', $department))->assertOk()->assertSee('Manage elections');
    }

    #[Test]
    public function only_nacos_admins_manage_departments(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.departments.index'))->assertForbidden();
        $this->actingAs(User::factory()->role(Role::Admin)->create())->get(route('admin.departments.index'))->assertOk()->assertSee('Food Science and Technology');
    }

    #[Test]
    public function students_on_the_roll_register_once_and_others_cannot(): void
    {
        $this->onRoll($this->fst, 'F/ND/24/3001001');

        $this->post(route('vote.register.store', $this->fst), [
            'fullname' => 'Not On Roll', 'matric_number' => 'F/ND/24/3009999', 'password' => 'Password1!', 'password_confirmation' => 'Password1!',
        ])->assertSessionHasErrors('matric_number');

        $this->post(route('vote.register.store', $this->fst), [
            'fullname' => 'Tolu Student', 'matric_number' => 'f/nd/24/3001001', 'email' => 'Tolu@Mail.test', 'password' => 'Password1!', 'password_confirmation' => 'Password1!',
        ])->assertRedirect(route('vote.home', $this->fst));

        $voter = Voter::where('matric_number', 'F/ND/24/3001001')->firstOrFail();
        // Signed in by name, never as the default guard: the sessions table
        // keys NACOS users' devices by the default guard's id.
        $this->assertSame('web', auth()->getDefaultDriver());
        $this->assertSame($this->fst->id, $voter->organization_id);
        $this->assertSame(2024, $voter->entry_year);
        $this->assertSame('tolu@mail.test', $voter->email);
        $this->assertAuthenticatedAs($voter, 'voter');
        // Not a NACOS account.
        $this->assertGuest('web');
        $this->assertSame(0, User::where('matric_number', 'F/ND/24/3001001')->count());

        auth('voter')->logout();
        $this->post(route('vote.register.store', $this->fst), [
            'fullname' => 'Someone Else', 'matric_number' => 'F/ND/24/3001001', 'password' => 'Password1!', 'password_confirmation' => 'Password1!',
        ])->assertSessionHasErrors('matric_number');
    }

    #[Test]
    public function a_registered_student_votes_and_the_ballot_stays_secret_and_counted_once(): void
    {
        $this->onRoll($this->fst, 'F/ND/24/3001001');
        $this->onRoll($this->fst, 'F/ND/24/3001002');
        $voter = $this->voter($this->fst);
        $this->voter($this->fst, ['matric_number' => 'F/ND/24/3001002', 'fullname' => 'Second']);
        $election = $this->openElection($this->fst);
        $position = $election->positions()->firstOrFail();
        $bola = $position->candidates()->where('name', 'Bola')->firstOrFail();

        $this->actingAs($voter, 'voter')
            ->get(route('vote.elections.show', [$this->fst, $election]))
            ->assertOk()
            ->assertSee('Cast my vote');

        $this->post(route('vote.elections.vote', [$this->fst, $election]), ['choices' => [$position->id => $bola->id]])
            ->assertRedirect(route('vote.elections.show', [$this->fst, $election]));

        $this->assertSame(1, ElectionCandidate::findOrFail($bola->id)->votes_count);
        $this->assertDatabaseHas('organization_election_voters', ['election_id' => $election->id, 'voter_id' => $voter->id]);
        $this->assertSame(0, DB::table('election_voters')->count());
        $this->assertSame(1, $election->ballotCount());

        $results = LiveResults::read($election);
        $this->assertSame(2, $results['electorate']);
        $this->assertSame(1, $results['ballots']);

        // A second ballot isn't counted.
        $this->post(route('vote.elections.vote', [$this->fst, $election]), ['choices' => [$position->id => $bola->id]])
            ->assertSessionHas('status');
        $this->assertSame(1, ElectionCandidate::findOrFail($bola->id)->votes_count);
    }

    #[Test]
    public function accounts_made_during_voting_and_students_off_the_roll_cannot_vote(): void
    {
        $this->onRoll($this->fst, 'F/ND/24/3001001');
        $election = $this->openElection($this->fst);
        $position = $election->positions()->firstOrFail();
        $candidate = $position->candidates()->firstOrFail();

        $this->travel(1)->minutes();
        $late = $this->voter($this->fst);
        $this->actingAs($late, 'voter')
            ->post(route('vote.elections.vote', [$this->fst, $election]), ['choices' => [$position->id => $candidate->id]])
            ->assertSessionHasErrors('ballot');

        $offRoll = $this->voter($this->fst, ['matric_number' => 'F/ND/24/3005555']);
        $offRoll->forceFill(['created_at' => now()->subDay()])->save();
        $this->assertStringContainsString('nominal roll', (string) $election->ineligibilityReason($offRoll->refresh()));

        $this->assertSame(0, $election->ballotCount());
    }

    #[Test]
    public function department_elections_never_appear_in_the_nacos_app_and_spaces_stay_apart(): void
    {
        $this->onRoll($this->fst, 'F/ND/24/3001001');
        $fstElection = $this->openElection($this->fst);
        $nacosElection = Election::factory()->open()->withBallot()->create(['title' => 'NACOS General Election']);
        $other = Organization::create(['name' => 'Mass Communication', 'slug' => 'mass-comm']);

        $student = User::factory()->create();
        $this->actingAs($student)->get(route('elections.index'))
            ->assertOk()->assertSee('NACOS General Election')->assertDontSee('FST Primaries');
        $this->get(route('elections.show', $fstElection))->assertNotFound();
        $this->get(route('home'))->assertDontSee('FST Primaries');

        // Each space only finds its own elections.
        $this->get(route('vote.elections.show', [$this->fst, $nacosElection]))->assertNotFound();
        $this->get(route('vote.elections.show', [$other, $fstElection]))->assertNotFound();
        $this->get(route('vote.home', $this->fst))->assertSee('FST Primaries')->assertDontSee('NACOS General Election');

        // A NACOS admin isn't a department admin, and a NACOS student can't vote there.
        $nacosAdmin = User::factory()->role(Role::Admin)->create();
        $this->actingAs($nacosAdmin)->get(route('vote.admin.elections', $this->fst))->assertRedirect(route('vote.login', $this->fst));
        $this->actingAs($nacosAdmin)->get(route('elections.manage'))->assertSee('NACOS General Election')->assertDontSee('FST Primaries');
        $this->assertSame(1, Election::query()->count());
        $this->assertSame(2, Election::everywhere()->count());
    }

    #[Test]
    public function voter_accounts_only_work_in_their_own_space(): void
    {
        $other = Organization::create(['name' => 'Mass Communication', 'slug' => 'mass-comm']);
        $voter = $this->voter($this->fst);

        // Not in another department's space…
        $this->post(route('vote.login.store', $other), ['login' => $voter->matric_number, 'password' => 'Password1!'])
            ->assertSessionHasErrors('login');
        $this->actingAs($voter, 'voter')->get(route('vote.home', $other))->assertOk();
        $this->assertGuest('voter');

        // …and never in the NACOS app.
        $this->post(route('login.store'), ['matric_number' => $voter->matric_number, 'password' => 'Password1!'])
            ->assertSessionHasErrors();
        $this->assertGuest('web');

        // A voter isn't an admin of their department.
        $this->actingAs($voter, 'voter')->get(route('vote.admin.roll', $this->fst))->assertForbidden();
    }

    #[Test]
    public function the_department_admin_runs_an_election_with_the_shared_setup_screens(): void
    {
        $this->actingAs($this->admin, 'voter');

        $this->post(route('vote.admin.elections.store', $this->fst), ['title' => 'Class Rep ND1', 'levels' => ['ND1'], 'arms' => ['swd'], 'roll_only' => '0'])
            ->assertRedirect();
        $election = $this->fst->elections()->firstOrFail();
        $this->assertSame($this->fst->id, $election->organization_id);
        $this->assertNull($election->created_by);
        // Always for the roll; departments have no courses.
        $this->assertTrue($election->roll_only);
        $this->assertNull($election->arms);
        $this->assertSame(['ND1'], $election->levels);

        $this->get(route('vote.admin.elections.show', [$this->fst, $election]))->assertOk()->assertSee('Class Rep ND1')->assertSee('Open voting');

        $this->post(route('vote.admin.elections.positions.store', [$this->fst, $election]), ['title' => 'Class Rep'])->assertRedirect();
        $position = $election->positions()->firstOrFail();
        $this->post(route('vote.admin.elections.candidates.store', [$this->fst, $election, $position]), ['name' => 'Bola', 'matric_number' => 'F/ND/24/3001001'])->assertRedirect();
        $candidate = $position->candidates()->firstOrFail();
        // Never linked to a NACOS account with the same matric number.
        $this->assertNull($candidate->user_id);

        $this->post(route('vote.admin.elections.launch', [$this->fst, $election]), ['hours' => 2])->assertSessionHasErrors('launch');
        $this->onRoll($this->fst, 'F/ND/24/3001001');
        $this->post(route('vote.admin.elections.launch', [$this->fst, $election]), ['hours' => 2])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(ElectionStatus::Open, $election->refresh()->status);

        // Started: details can't change any more.
        $this->put(route('vote.admin.elections.update', [$this->fst, $election]), ['title' => 'Renamed'])->assertForbidden();

        $this->post(route('vote.admin.elections.close', [$this->fst, $election]))->assertRedirect();
        $this->assertSame(ElectionStatus::Closed, $election->refresh()->status);
        $this->assertTrue(LiveResults::read($election)['final']);
    }

    #[Test]
    public function the_department_admin_uploads_the_roll_and_it_locks_while_voting_is_open(): void
    {
        $this->actingAs($this->admin, 'voter');
        $csv = UploadedFile::fake()->createWithContent('roll.csv', "Matric number,Full name,Email\nF/ND/24/3001001,OKAFOR Tolu,tolu@mail.test\nP/ND/24/3001002,Wrong Programme,\n");

        $this->post(route('vote.admin.roll.store', $this->fst), ['programme' => 'full_time', 'level' => 'ND1', 'roll' => $csv])
            ->assertSessionHasErrors('roll');

        $this->post(route('vote.admin.roll.store', $this->fst), ['programme' => 'full_time', 'level' => 'ND1', 'roll' => $csv, 'confirm' => '1'])
            ->assertRedirect(route('vote.admin.roll', $this->fst));
        $this->assertSame(2, $this->fst->roll()->count());
        $this->assertSame(Level::ND1, $this->fst->roll()->where('matric_number', 'F/ND/24/3001001')->firstOrFail()->level);
        // Nothing went to the NACOS roll.
        $this->assertSame(0, DB::table('nominal_roll')->count());

        $this->get(route('vote.admin.roll', $this->fst))->assertOk()->assertSee('OKAFOR Tolu');

        $this->openElection($this->fst);
        $this->post(route('vote.admin.roll.students.store', $this->fst), ['matric_number' => 'F/ND/24/3001003', 'fullname' => 'Late', 'programme' => 'full_time', 'level' => 'ND1'])
            ->assertSessionHasErrors('roll');
        $this->assertSame(2, $this->fst->roll()->count());
    }

    #[Test]
    public function the_department_admin_replaces_a_forgotten_password_and_suspends_accounts(): void
    {
        $voter = $this->voter($this->fst);

        $this->actingAs($this->admin, 'voter')
            ->post(route('vote.admin.voters.password', [$this->fst, $voter->id]))
            ->assertSessionHas('slip', fn (array $slip) => $slip['login'] === 'F/ND/24/3001001');
        $voter->refresh();
        $this->assertTrue($voter->must_change_password);
        $this->assertTrue(Hash::check(session('slip')['password'], $voter->password));

        $this->put(route('vote.admin.voters.status', [$this->fst, $voter->id]), ['status' => 'suspended']);
        $this->assertSame(UserStatus::Suspended, $voter->refresh()->status);

        // Another department's accounts aren't reachable.
        $outsider = $this->voter(Organization::create(['name' => 'Other', 'slug' => 'other']));
        $this->post(route('vote.admin.voters.password', [$this->fst, $outsider->id]))->assertNotFound();
    }

    #[Test]
    public function a_whole_campus_on_one_address_can_sign_in_but_guessing_is_slowed(): void
    {
        // Many students behind one campus IP sign in within a minute.
        for ($i = 1; $i <= 15; $i++) {
            $matric = sprintf('F/ND/24/30020%02d', $i);
            $this->voter($this->fst, ['matric_number' => $matric, 'fullname' => "Student {$i}"]);
            $this->post(route('vote.login.store', $this->fst), ['login' => $matric, 'password' => 'Password1!'])
                ->assertRedirect(route('vote.home', $this->fst));
            auth('voter')->logout();
        }

        // Guessing one account's password is stopped after five tries.
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('vote.login.store', $this->fst), ['login' => 'F/ND/24/3002001', 'password' => 'wrong-'.$i]);
        }
        $this->post(route('vote.login.store', $this->fst), ['login' => 'F/ND/24/3002001', 'password' => 'Password1!'])
            ->assertSessionHasErrors(['login' => 'Too many attempts. Try again in 60 seconds.']);
        $this->assertGuest('voter');
    }

    #[Test]
    public function a_paused_space_shows_a_notice_and_nobody_can_sign_in(): void
    {
        $this->fst->forceFill(['status' => UserStatus::Suspended])->save();

        $this->get(route('vote.home', $this->fst))->assertStatus(503)->assertSee('paused');
        $this->post(route('vote.login.store', $this->fst), ['login' => 'ada@fst.test', 'password' => 'Password1!'])->assertStatus(503);
        $this->assertGuest('voter');
    }

    #[Test]
    public function the_scheduler_closes_department_elections_on_time(): void
    {
        $election = $this->openElection($this->fst);
        $election->forceFill(['ends_at' => now()->subMinute()])->save();

        $this->artisan('elections:close')->assertSuccessful();

        $this->assertSame(ElectionStatus::Closed, $election->refresh()->status);
    }
}
