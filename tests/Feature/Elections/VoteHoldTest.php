<?php

namespace Tests\Feature\Elections;

use App\Enums\Role;
use App\Http\Middleware\DeviceId;
use App\Models\AuditLog;
use App\Models\Election;
use App\Models\User;
use App\Support\Elections\LiveResults;
use App\Support\Elections\SharedDevices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Accounts registered in someone else's name (docs/vote-integrity.md):
 * held accounts can't vote until an admin releases them, and Shared
 * devices shows accounts one phone acted for.
 */
class VoteHoldTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->role(Role::Admin)->create(['fullname' => 'Ada Admin']);
    }

    private function election(): Election
    {
        return Election::factory()->open()->withBallot(['President' => 2])->create(['allow_late_accounts' => true]);
    }

    #[Test]
    public function a_held_account_cannot_vote_until_released_even_during_the_election(): void
    {
        $election = $this->election();
        $position = $election->positions()->firstOrFail();
        $candidate = $position->candidates()->firstOrFail();
        $student = User::factory()->create(['fullname' => 'Tolu Okafor']);

        $this->actingAs($this->admin)
            ->post(route('admin.users.vote-hold', $student), ['reason' => 'Same phone as 6 other accounts'])
            ->assertSessionHas('status');

        $student->refresh();
        $this->assertTrue($student->isOnVoteHold());
        $this->assertSame($this->admin->id, $student->vote_held_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'vote_hold_placed', 'subject_id' => $student->id, 'user_id' => $this->admin->id]);

        // The admin counts as eligible too (setUp); the student is held.
        $this->assertSame(1, $election->electorate()->count());
        $this->assertSame(1, $election->heldVoters()->count());
        $results = LiveResults::build($election);
        $this->assertSame(1, $results['electorate']);
        $this->assertSame(1, $results['held']);

        $this->actingAs($student)->get(route('elections.show', $election))->assertOk()
            ->assertSee('on hold for voting')->assertDontSee('Cast my vote');
        $this->post(route('elections.vote', $election), ['choices' => [$position->id => $candidate->id]]);
        $this->assertFalse($election->hasVoted($student));

        // Checked in person on election day: votes at once.
        $this->actingAs($this->admin)
            ->delete(route('admin.users.vote-release', $student), ['note' => 'Saw their student ID in person'])
            ->assertSessionHas('status');
        $this->assertFalse($student->refresh()->isOnVoteHold());
        $this->assertEqualsCanonicalizing(['name' => 'Tolu Okafor', 'matric' => $student->matric_number, 'reason' => 'Same phone as 6 other accounts', 'note' => 'Saw their student ID in person'],
            AuditLog::query()->where('action', 'vote_hold_released')->firstOrFail()->meta);

        $results = LiveResults::build($election);
        $this->assertSame(2, $results['electorate']);
        $this->assertSame(0, $results['held']);

        $this->actingAs($student)->post(route('elections.vote', $election), ['choices' => [$position->id => $candidate->id]]);
        $this->assertTrue($election->hasVoted($student));
    }

    #[Test]
    public function holds_need_a_reason_releases_a_note_and_admins_cannot_hold_themselves(): void
    {
        $student = User::factory()->create();

        $this->actingAs($this->admin)->post(route('admin.users.vote-hold', $student), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('admin.users.vote-hold', $this->admin), ['reason' => 'Testing'])->assertForbidden();

        $this->post(route('admin.users.vote-hold', $student), ['reason' => 'Suspicious'])->assertSessionHasNoErrors();
        $this->delete(route('admin.users.vote-release', $student), ['note' => ''])->assertSessionHasErrors('note');
        $this->assertTrue($student->refresh()->isOnVoteHold());

        // Only admins.
        $rep = User::factory()->role(Role::CourseRep)->create();
        $this->actingAs($rep)->get(route('admin.vote-holds.index'))->assertForbidden();
        $this->post(route('admin.users.vote-hold', $student), ['reason' => 'x'])->assertForbidden();
    }

    #[Test]
    public function the_holds_page_lists_held_accounts_and_users_can_be_filtered_by_hold(): void
    {
        $held = User::factory()->create(['fullname' => 'Held Person']);
        User::factory()->create(['fullname' => 'Free Person']);

        $this->actingAs($this->admin)->post(route('admin.users.vote-hold', $held), ['reason' => 'Registered 2 minutes after 5 others']);

        $this->get(route('admin.vote-holds.index'))->assertOk()->assertSee('Held Person')->assertSee('Registered 2 minutes after 5 others')->assertDontSee('Free Person');
        $this->get(route('admin.users.index', ['status' => 'on-hold']))->assertOk()->assertSee('Held Person')->assertDontSee('Free Person');
        $this->get(route('admin.users.show', $held))->assertOk()->assertSee('Release for voting');
    }

    #[Test]
    public function every_browser_gets_an_anonymous_device_id_written_with_sign_ups_and_votes(): void
    {
        $response = $this->get(route('login'));
        $response->assertCookie(DeviceId::COOKIE);

        $student = User::factory()->create(['matric_number' => 'F/ND/24/1234567']);
        $this->withCookie(DeviceId::COOKIE, str_repeat('a', 32))
            ->post(route('login.store'), ['matric_number' => 'F/ND/24/1234567', 'password' => 'Password1!']);

        $login = AuditLog::query()->where('action', 'login')->where('user_id', $student->id)->firstOrFail();
        $this->assertSame(str_repeat('a', 32), $login->device_id);
        $this->assertSame(40, strlen((string) $login->network_key));
    }

    #[Test]
    public function shared_devices_groups_accounts_one_phone_signed_up_and_voted_for(): void
    {
        $device = str_repeat('b', 32);
        $victims = User::factory()->count(3)->create();
        $other = User::factory()->create(['fullname' => 'Someone Else']);

        foreach ($victims as $victim) {
            $this->entry('user_registered', $victim, $device);
            $this->entry('election_voted', $victim, $device);
        }
        $this->entry('login', $other, str_repeat('c', 32));

        $groups = SharedDevices::groups('device', now()->subDay(), now()->addDay());
        $this->assertCount(1, $groups);
        $this->assertSame(3, $groups[0]['registered']);
        $this->assertSame(3, $groups[0]['voted']);

        $this->actingAs($this->admin)->get(route('admin.vote-holds.devices'))->assertOk()
            ->assertSee($victims[0]->fullname)->assertDontSee('Someone Else');

        // Held together, with one reason.
        $this->post(route('admin.vote-holds.store-many'), ['users' => $victims->pluck('id')->all(), 'reason' => 'Same device'])
            ->assertSessionHas('status', '3 accounts are on hold.');
        $this->assertSame(3, User::query()->whereNotNull('vote_held_at')->count());

        // From one account, its device's other accounts.
        $this->get(route('admin.users.show', $victims[0]))->assertSee('Seen on the same device')->assertSee($victims[1]->fullname);
    }

    #[Test]
    public function a_whole_group_is_held_at_once_and_the_report_exports_as_csv(): void
    {
        $device = str_repeat('e', 32);
        $accounts = User::factory()->count(4)->create();
        foreach ($accounts as $account) {
            $this->entry('user_registered', $account, $device);
        }
        $operator = User::factory()->create(['fullname' => 'Operator Person']);
        $this->entry('login', $operator, $device);
        $bystander = User::factory()->create(['fullname' => 'Bystander']);
        $this->entry('login', $bystander, str_repeat('f', 32));

        $this->actingAs($this->admin)->get(route('admin.vote-holds.devices'))->assertOk()
            ->assertSee('Put all 5 on hold')->assertSee('Only signed in here');

        $csv = $this->get(route('admin.vote-holds.devices.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Operator Person', $csv);
        $this->assertStringContainsString($accounts[0]->matric_number, $csv);
        $this->assertStringNotContainsString('Bystander', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'shared_devices_exported', 'user_id' => $this->admin->id]);

        $this->post(route('admin.vote-holds.store-many'), ['group' => $device, 'kind' => 'device', 'reason' => 'Bulk sign-ups'])
            ->assertSessionHas('status', '5 accounts are on hold.');
        $this->assertSame(5, User::query()->whereNotNull('vote_held_at')->count());
        $this->assertFalse($bystander->refresh()->isOnVoteHold());
    }

    #[Test]
    public function browser_details_are_shown_in_plain_words(): void
    {
        $this->assertSame('No browser details (a script or tool, not a phone browser)', SharedDevices::browser(''));
        $this->assertSame('Not a browser: python-requests/2.31.0', SharedDevices::browser('python-requests/2.31.0'));
        $this->assertSame('Chrome 131 on Android 10', SharedDevices::browser('Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36'));
        $this->assertSame('Safari 17 on iOS 17', SharedDevices::browser('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1'));
    }

    private function entry(string $action, User $user, string $device): void
    {
        AuditLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'ip_address' => '10.0.0.1',
            'user_agent' => 'Test',
            'device_id' => $device,
            'network_key' => sha1('10.0.0.1|Test'),
        ]);
    }
}
