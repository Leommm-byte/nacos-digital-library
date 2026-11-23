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
use App\Support\Accounts\Invitations;
use App\Support\Classes\SchoolClass;
use App\Support\Elections\RollEmailVerification;
use App\Support\Elections\RollImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
    public function the_login_and_forgot_pages_point_locked_out_students_to_verify(): void
    {
        $this->get(route('login'))->assertOk()->assertSee(route('verify-account'));
        $this->get(route('password.request'))->assertOk()->assertSee(route('verify-account'));
    }

    #[Test]
    public function a_logged_in_unverified_student_can_open_and_accept_the_link(): void
    {
        $this->onRoll('F/ND/25/3210050', 'deji@gmail.com');
        $election = Election::factory()->requireRollEmail()->open()->withBallot(['President' => 2])->create();
        $user = User::factory()->create(['matric_number' => 'F/ND/25/3210050', 'password' => 'Password1!']);

        // Logged in with their own password, but not verified: can't vote yet.
        $this->assertTrue($election->needsRollEmailVerification($user));

        $this->actingAs($user)->post(route('verify-account.store'), ['matric_number' => 'F/ND/25/3210050'])
            ->assertRedirect(route('verify-account.sent'));
        $token = $this->linkFor($user);

        // The link opens and is accepted while still signed in (not bounced
        // away by guest-only middleware).
        $this->actingAs($user)->get(route('invitation.show', $token))->assertOk();
        $this->actingAs($user)->post(route('invitation.store', $token), ['password' => 'NewPass1!', 'password_confirmation' => 'NewPass1!'])
            ->assertRedirect(route('home'));

        $this->assertTrue($user->refresh()->hasVerifiedRollEmail());
    }

    #[Test]
    public function unverified_students_see_a_confirm_your_account_banner_during_an_election(): void
    {
        $this->onRoll('F/ND/25/3210060', 'x@gmail.com');
        $user = User::factory()->create(['matric_number' => 'F/ND/25/3210060']);

        // No verification election yet: no nudge.
        $this->actingAs($user)->get(route('home'))->assertOk()->assertDontSee('Confirm your account so you can vote');

        // A NACOS election that needs verification (draft or open): nudge shows.
        Election::factory()->requireRollEmail()->create();
        Cache::forget('elections:roll-email-nudge');
        $this->actingAs($user)->get(route('home'))->assertOk()
            ->assertSee('Confirm your account so you can vote')
            ->assertSee(route('verify-account'));

        // Once verified, the nudge is gone.
        $user->forceFill(['roll_email_verified_at' => now()])->save();
        Cache::forget('elections:roll-email-nudge');
        $this->actingAs($user)->get(route('home'))->assertOk()->assertDontSee('Confirm your account so you can vote');
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
    public function queueing_links_targets_only_newly_emailed_students_never_sent_a_link(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $this->onRoll('F/ND/25/3210010', 'a@gmail.com'); // email just added
        $this->onRoll('F/ND/25/3210011', 'b@gmail.com'); // no class email on roll
        $this->onRoll('F/ND/25/3210012', '');
        $this->onRoll('F/ND/25/3210013', 'd@gmail.com');
        $this->onRoll('F/ND/25/3210014', 'e@gmail.com');

        // Needs a link: on the roll with an email, never invited.
        $u10 = User::factory()->create(['matric_number' => 'F/ND/25/3210010']);
        // No class email on the roll: can't be sent.
        $u12 = User::factory()->create(['matric_number' => 'F/ND/25/3210012']);
        // Already verified: left out.
        $u13 = User::factory()->create(['matric_number' => 'F/ND/25/3210013', 'roll_email_verified_at' => now()]);
        // "Locked to class list": signed up through the class email, so it
        // already has an invitation — left out.
        $u14 = User::factory()->create(['matric_number' => 'F/ND/25/3210014']);
        Invitations::queue($u14, by: $u14);

        $this->actingAs($admin)->post(route('admin.accounts.verifications'))->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('account_invitations', ['user_id' => $u10->id]);
        $this->assertDatabaseMissing('account_invitations', ['user_id' => $u12->id]); // no class email
        $this->assertDatabaseMissing('account_invitations', ['user_id' => $u13->id]); // already verified
        $this->assertSame(1, AccountInvitation::query()->where('user_id', $u14->id)->count()); // not a second one
        $this->assertSame(2, AccountInvitation::query()->count()); // u10 + u14's own

        // Idempotent: u10 now has an invitation, so a second run adds nothing.
        $this->actingAs($admin)->post(route('admin.accounts.verifications'))->assertRedirect();
        $this->assertSame(2, AccountInvitation::query()->count());
    }

    #[Test]
    public function queueing_skips_students_who_already_confirmed_the_just_added_email(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $this->onRoll('F/ND/25/3210030', 'tomi@gmail.com');
        // The student signed up with their own email and confirmed it; that
        // address has only now been added to the roll, so they haven't been
        // marked verified yet — but queueing should recognise it, not email.
        $tomi = User::factory()->create(['matric_number' => 'F/ND/25/3210030', 'email' => 'tomi@gmail.com', 'email_verified_at' => now()]);
        $this->assertFalse($tomi->hasVerifiedRollEmail());

        $this->actingAs($admin)->post(route('admin.accounts.verifications'))->assertRedirect();

        $this->assertTrue($tomi->refresh()->hasVerifiedRollEmail()); // recognised
        $this->assertDatabaseMissing('account_invitations', ['user_id' => $tomi->id]); // not emailed
    }

    #[Test]
    public function students_who_already_confirmed_their_class_email_are_treated_as_verified(): void
    {
        $this->onRoll('F/ND/25/3210020', 'kemi@gmail.com');
        // Signed up through the class email and claimed it: account email
        // matches the roll's and is confirmed.
        $claimed = User::factory()->create(['matric_number' => 'F/ND/25/3210020', 'email' => 'kemi@gmail.com', 'email_verified_at' => now()]);
        // Confirmed, but a different (attacker) address: not treated as verified.
        $this->onRoll('F/ND/25/3210021', 'real@gmail.com');
        $other = User::factory()->create(['matric_number' => 'F/ND/25/3210021', 'email' => 'attacker@evil.test', 'email_verified_at' => now()]);

        $marked = RollEmailVerification::backfill();

        $this->assertSame(1, $marked);
        $this->assertTrue($claimed->refresh()->hasVerifiedRollEmail());
        $this->assertFalse($other->refresh()->hasVerifiedRollEmail());
    }

    #[Test]
    public function an_admin_corrects_a_students_email_even_while_voting_is_open(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $entry = $this->onRoll('F/ND/25/3210040', 'old-lost@gmail.com');
        $student = User::factory()->create(['matric_number' => 'F/ND/25/3210040']);
        // An open roll-only election locks the roll for adds and removals.
        Election::factory()->requireRollEmail()->open()->withBallot(['President' => 2])->create(['roll_only' => true]);

        // Adding or removing is blocked while voting is open...
        $this->actingAs($admin)->delete(route('roll.students.destroy', $entry))->assertRedirect();
        $this->assertDatabaseHas('nominal_roll', ['id' => $entry->id]); // still there

        // ...but correcting the email, which doesn't change who may vote, works.
        $this->actingAs($admin)->put(route('roll.students.email', $entry), ['email' => 'New.Address@Gmail.com'])
            ->assertRedirect()->assertSessionHas('status');

        $this->assertSame('new.address@gmail.com', $entry->refresh()->email); // normalised
        $this->assertDatabaseHas('audit_logs', ['action' => 'roll_email_corrected']);
        // A fresh confirmation link is queued for the student, to the new address.
        $this->assertDatabaseHas('account_invitations', ['user_id' => $student->id]);
    }

    #[Test]
    public function a_bad_email_is_rejected_and_an_unchanged_one_is_a_no_op(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $entry = $this->onRoll('F/ND/25/3210041', 'ok@gmail.com');

        $this->actingAs($admin)->put(route('roll.students.email', $entry), ['email' => 'not-an-email'])->assertRedirect();
        $this->assertSame('ok@gmail.com', $entry->refresh()->email);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'roll_email_corrected']);
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
