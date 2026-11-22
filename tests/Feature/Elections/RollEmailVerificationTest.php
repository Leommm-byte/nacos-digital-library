<?php

namespace Tests\Feature\Elections;

use App\Enums\Level;
use App\Enums\Programme;
use App\Enums\Role;
use App\Models\AccountInvitation;
use App\Models\Election;
use App\Models\RollEntry;
use App\Models\User;
use App\Notifications\AccountInvitation as InvitationEmail;
use App\Support\Classes\SchoolClass;
use App\Support\Elections\RollImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Votes in someone else's name (docs/vote-integrity.md): voting can be
 * gated on proof that the voter controls the class-list email on the roll,
 * and students verify or reclaim their account through a link sent there.
 */
class RollEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function onRoll(string $matric, string $email): RollEntry
    {
        return RollEntry::create(['matric_number' => $matric, 'fullname' => 'Real Student', 'email' => $email, 'programme' => 'full_time', 'level' => 'ND1']);
    }

    private function linkFor(User $user): string
    {
        $this->artisan('invitations:send')->assertSuccessful();
        $email = Notification::sent($user, InvitationEmail::class)->last();
        $this->assertInstanceOf(InvitationEmail::class, $email);

        return $email->token;
    }

    #[Test]
    public function invitation_links_go_to_the_class_email_not_the_account_email(): void
    {
        $this->onRoll('F/ND/25/3210001', 'real.student@gmail.com');
        // An impersonator set a different address on the account.
        $user = User::factory()->create(['matric_number' => 'F/ND/25/3210001', 'email' => 'attacker@evil.test']);

        $this->assertSame('real.student@gmail.com', $user->routeNotificationForMail(new InvitationEmail('tok')));
    }

    #[Test]
    public function an_unverified_voter_is_blocked_then_votes_after_reclaiming_by_class_email(): void
    {
        $this->onRoll('F/ND/25/3210001', 'real.student@gmail.com');
        $election = Election::factory()->requireRollEmail()->open()->withBallot(['President' => 2])->create();
        $position = $election->positions()->firstOrFail();
        $candidate = $position->candidates()->firstOrFail();

        // The account was taken over: a different email, and on hold.
        $user = User::factory()->create(['matric_number' => 'F/ND/25/3210001', 'email' => 'attacker@evil.test', 'vote_held_at' => now(), 'vote_hold_reason' => 'Shared device']);

        // Can't vote: not verified.
        $this->assertNotNull($election->ineligibilityReason($user));
        $this->assertTrue($election->needsRollEmailVerification($user));
        $this->actingAs($user)->get(route('elections.show', $election))->assertOk()->assertSee('Verify your account to vote');
        $this->post(route('elections.vote', $election), ['choices' => [$position->id => $candidate->id]]);
        $this->assertFalse($election->hasVoted($user));
        $this->post(route('logout'));

        // Reclaim: enter the matric number, link goes to the class email.
        $this->post(route('verify-account.store'), ['matric_number' => 'f/nd/25/3210001'])
            ->assertRedirect(route('verify-account.sent'));
        $token = $this->linkFor($user);

        // Opening the link hands back the account and confirms the email.
        $this->get(route('invitation.show', $token))->assertOk();
        $this->post(route('invitation.store', $token), ['password' => 'Str0ng!pass', 'password_confirmation' => 'Str0ng!pass'])
            ->assertRedirect(route('home'));

        $user->refresh();
        $this->assertTrue($user->hasVerifiedRollEmail());
        $this->assertSame('real.student@gmail.com', $user->email); // reset from the attacker's
        $this->assertFalse($user->isOnVoteHold()); // the hold cleared itself
        $this->assertDatabaseHas('audit_logs', ['action' => 'roll_email_verified', 'subject_id' => $user->id]);

        // Now the ballot is accepted.
        $this->assertNull($election->ineligibilityReason($user->refresh()));
        $this->post(route('elections.vote', $election), ['choices' => [$position->id => $candidate->id]]);
        $this->assertTrue($election->hasVoted($user));
    }

    #[Test]
    public function verify_points_students_without_an_account_to_signing_up(): void
    {
        $this->onRoll('F/ND/25/3210002', 'nobody@gmail.com');

        $this->post(route('verify-account.store'), ['matric_number' => 'F/ND/25/3210002'])
            ->assertRedirect()
            ->assertSessionHas('verify.signup');
        $this->assertDatabaseCount('account_invitations', 0);
    }

    #[Test]
    public function verify_is_vague_when_the_roll_has_no_class_email(): void
    {
        User::factory()->create(['matric_number' => 'F/ND/25/3210003']); // account, but matric not on the roll

        $this->post(route('verify-account.store'), ['matric_number' => 'F/ND/25/3210003'])
            ->assertSessionHas('verify.problem');
        $this->assertDatabaseCount('account_invitations', 0);
    }

    #[Test]
    public function an_admin_queues_verification_links_for_everyone_unconfirmed(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $this->onRoll('F/ND/25/3210010', 'a@gmail.com');
        $this->onRoll('F/ND/25/3210011', 'b@gmail.com');
        $this->onRoll('F/ND/25/3210012', ''); // no class email: skipped
        $this->onRoll('F/ND/25/3210013', 'd@gmail.com');
        $u10 = User::factory()->create(['matric_number' => 'F/ND/25/3210010']);
        $u11 = User::factory()->create(['matric_number' => 'F/ND/25/3210011']);
        $u12 = User::factory()->create(['matric_number' => 'F/ND/25/3210012']);
        // Already verified: not re-queued.
        $u13 = User::factory()->create(['matric_number' => 'F/ND/25/3210013', 'roll_email_verified_at' => now()]);

        $this->actingAs($admin)->post(route('admin.accounts.verifications'))->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('account_invitations', ['user_id' => $u10->id]);
        $this->assertDatabaseHas('account_invitations', ['user_id' => $u11->id]);
        $this->assertDatabaseMissing('account_invitations', ['user_id' => $u12->id]); // no class email
        $this->assertDatabaseMissing('account_invitations', ['user_id' => $u13->id]); // already verified
        $this->assertSame(2, AccountInvitation::query()->count());

        // Running again doesn't pile up duplicates (idempotent).
        $this->actingAs($admin)->post(route('admin.accounts.verifications'))->assertRedirect();
        $this->assertSame(2, AccountInvitation::query()->count());
    }

    #[Test]
    public function reuploading_the_roll_fills_emails_without_wiping_them(): void
    {
        $class = SchoolClass::make(Programme::FullTime, Level::ND1);
        $rows = [
            'F/ND/25/3210001' => ['matric_number' => 'F/ND/25/3210001', 'fullname' => 'A', 'email' => 'a@gmail.com'],
            'F/ND/25/3210002' => ['matric_number' => 'F/ND/25/3210002', 'fullname' => 'B', 'email' => null],
        ];
        RollImport::saveClass($rows, $class);

        // Re-upload: one email added, one changed, and a blank cell must not
        // wipe the address already on the roll.
        $again = [
            'F/ND/25/3210001' => ['matric_number' => 'F/ND/25/3210001', 'fullname' => 'A', 'email' => 'a.new@gmail.com'],
            'F/ND/25/3210002' => ['matric_number' => 'F/ND/25/3210002', 'fullname' => 'B', 'email' => 'b@gmail.com'],
            'F/ND/25/3210003' => ['matric_number' => 'F/ND/25/3210003', 'fullname' => 'C', 'email' => null],
        ];
        $result = RollImport::saveClass($again, $class);

        $this->assertSame(['filled' => 1, 'changed' => 1, 'unchanged' => 0, 'kept' => 0], $result['emails']);
        $this->assertSame('a.new@gmail.com', RollEntry::emailFor('F/ND/25/3210001'));
        $this->assertSame('b@gmail.com', RollEntry::emailFor('F/ND/25/3210002'));

        // A blank re-upload keeps the existing address.
        $blank = ['F/ND/25/3210001' => ['matric_number' => 'F/ND/25/3210001', 'fullname' => 'A', 'email' => null]];
        $kept = RollImport::saveClass($blank, $class);
        $this->assertSame(1, $kept['emails']['kept']);
        $this->assertSame('a.new@gmail.com', RollEntry::emailFor('F/ND/25/3210001'));
    }
}
