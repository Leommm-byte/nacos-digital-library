<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\AccountInvitation;
use App\Models\Department;
use App\Models\RollEntry;
use App\Models\User;
use App\Notifications\AccountInvitation as InvitationEmail;
use App\Support\Accounts\Invitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->department = Department::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->for($this->department)->role(Role::Admin)->create(['fullname' => 'Ada Admin']);
    }

    /**
     * Sends what's waiting and returns the link each person was sent.
     *
     * @return array<string, string> link by matric number
     */
    private function send(): array
    {
        $this->artisan('invitations:send')->assertSuccessful();
        $links = [];

        foreach (User::all() as $user) {
            $email = Notification::sent($user, InvitationEmail::class)->last();
            if ($email instanceof InvitationEmail) {
                $links[$user->matric_number] = $email->token;
            }
        }

        return $links;
    }

    #[Test]
    public function students_with_an_email_on_the_roll_are_invited_and_the_rest_get_slips(): void
    {
        RollEntry::create(['matric_number' => 'F/HD/24/0000001', 'fullname' => 'OBI Adaeze', 'email' => 'ada@example.com', 'programme' => 'full_time', 'level' => 'HND1']);
        RollEntry::create(['matric_number' => 'F/HD/24/0000002', 'fullname' => 'BELLO Tunde', 'programme' => 'full_time', 'level' => 'HND1']);

        $this->actingAs($this->admin)->get(route('admin.accounts.index'))->assertOk()->assertSee('Create accounts and email invitations');

        $this->post(route('admin.accounts.store'), ['classes' => ['full_time|HND1'], 'department_id' => $this->department->id, 'send' => 'email'])
            ->assertRedirect(route('admin.accounts.slips'));

        // Tunde has no email: a slip. Ada: an invitation, and no slip.
        /** @var list<array{matric: string}> $slips */
        $slips = session('slips')['slips'];
        $this->assertSame(['F/HD/24/0000002'], array_column($slips, 'matric'));
        $this->get(route('admin.accounts.slips'))->assertSee('1 student with an email will get an invitation instead of a slip');

        $ada = User::where('matric_number', 'F/HD/24/0000001')->firstOrFail();
        $this->assertTrue($ada->must_change_password);
        $this->assertSame($this->admin->id, $ada->invitation?->invited_by);
        $this->assertSame(AccountInvitation::WAITING, $ada->invitation->status());

        // Nothing is emailed until the scheduler runs.
        Notification::assertNothingSent();
        $links = $this->send();
        $this->assertSame(['F/HD/24/0000001'], array_keys($links));
        $this->assertSame(AccountInvitation::SENT, $ada->invitation->fresh()?->status());

        // The link: choose a password, and you're in.
        auth()->logout();
        $this->get(route('invitation.show', $links['F/HD/24/0000001']))->assertOk()->assertSee('F/HD/24/0000001');
        $this->post(route('invitation.store', $links['F/HD/24/0000001']), ['password' => 'weak', 'password_confirmation' => 'weak'])->assertSessionHasErrors('password');
        $this->post(route('invitation.store', $links['F/HD/24/0000001']), ['password' => 'N3w!password', 'password_confirmation' => 'N3w!password'])
            ->assertRedirect(route('home'));

        $ada->refresh();
        $this->assertAuthenticatedAs($ada);
        $this->assertTrue(Hash::check('N3w!password', $ada->password));
        $this->assertFalse($ada->must_change_password);
        $this->assertNotNull($ada->email_verified_at);
        $this->assertSame(AccountInvitation::ACCEPTED, $ada->invitation?->status());

        // Once only.
        auth()->logout();
        $this->get(route('invitation.show', $links['F/HD/24/0000001']))->assertStatus(410)->assertSee('This link has expired');
        $this->get(route('invitation.show', 'not-a-real-token'))->assertStatus(410);
    }

    #[Test]
    public function invitations_go_out_within_the_hourly_limit_sign_ups_first(): void
    {
        config(['mail.invitations.per_hour' => 3, 'mail.invitations.per_minute' => 2]);

        $class = User::factory()->count(4)->create();
        foreach ($class as $user) {
            Invitations::queue($user, $this->admin);
        }
        $signup = User::factory()->create();
        Invitations::queue($signup, $signup, priority: true);

        $this->artisan('invitations:send');
        Notification::assertSentTo($signup, InvitationEmail::class);
        Notification::assertSentTimes(InvitationEmail::class, 2);

        $this->artisan('invitations:send');
        Notification::assertSentTimes(InvitationEmail::class, 3);

        // The hour's allowance is used up.
        $this->artisan('invitations:send');
        Notification::assertSentTimes(InvitationEmail::class, 3);
        $this->assertSame(2, AccountInvitation::query()->waiting()->count());

        $this->travel(61)->minutes();
        $this->artisan('invitations:send');
        Notification::assertSentTimes(InvitationEmail::class, 5);
    }

    #[Test]
    public function admins_see_unused_invitations_and_send_them_again(): void
    {
        $sent = User::factory()->create(['fullname' => 'Sent Person', 'email' => 'sent@example.com']);
        $wrong = User::factory()->create(['fullname' => 'Wrong Address', 'email' => 'typo@example.con']);
        $used = User::factory()->create(['fullname' => 'Used Already']);
        foreach ([$sent, $wrong, $used] as $user) {
            Invitations::queue($user, $this->admin);
        }
        $links = $this->send();
        $used->invitation?->forceFill(['accepted_at' => now()])->save();

        $this->actingAs($this->admin)->get(route('admin.accounts.invitations'))->assertOk()
            ->assertSee('Sent Person')->assertSee('Wrong Address')->assertDontSee('Used Already');

        // A week later the links have expired.
        $this->travel(8)->days();
        $this->get(route('admin.accounts.invitations', ['status' => 'expired']))->assertSee('Sent Person')->assertSee('Re-send all 2 expired');
        $this->assertNull(Invitations::find($links[$sent->matric_number]));

        // Corrected address: the old link stops working at once, and the
        // new one goes to the right place, first.
        $this->travelBack();
        $this->post(route('admin.accounts.invitations.resend', $wrong->invitation), ['email' => ' Right@Example.com '])->assertSessionHasNoErrors();
        $this->assertSame('right@example.com', $wrong->fresh()?->email);
        $this->assertNull(Invitations::find($links[$wrong->matric_number]));
        $this->assertSame(AccountInvitation::WAITING, $wrong->invitation->fresh()?->status());

        $this->post(route('admin.accounts.invitations.resend', $sent->invitation), ['email' => $this->admin->email])
            ->assertSessionHasErrorsIn('invitation-'.$sent->invitation?->id, 'email');

        $this->travel(8)->days();
        $this->post(route('admin.accounts.invitations.expired'))->assertSessionHas('status', fn (string $status) => str_contains($status, '1 expired invitation queued'));
        $this->assertSame(2, AccountInvitation::query()->waiting()->count());

        // A used invitation can't be sent again.
        $this->post(route('admin.accounts.invitations.resend', $used->invitation))->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['action' => 'invitation_resent']);
    }

    #[Test]
    public function only_admins_manage_invitations(): void
    {
        $user = User::factory()->create();
        $invitation = Invitations::queue($user, $this->admin);

        foreach ([Role::Student, Role::CourseRep, Role::Governor] as $role) {
            $this->actingAs(User::factory()->role($role)->create());
            $this->get(route('admin.accounts.invitations'))->assertForbidden();
            $this->post(route('admin.accounts.invitations.resend', $invitation))->assertForbidden();
            $this->post(route('admin.accounts.invitations.expired'))->assertForbidden();
        }
    }

    #[Test]
    public function forgot_password_sends_an_unused_invitation_again(): void
    {
        $user = User::factory()->create(['email' => 'me@example.com', 'email_verified_at' => null]);
        Invitations::queue($user, $this->admin);
        $old = $this->send()[$user->matric_number];
        $invitation = $user->invitation;
        $this->assertNotNull($invitation);

        // Just sent: not again yet.
        $this->post(route('password.email'), ['matric_number' => $user->matric_number]);
        $this->assertSame(AccountInvitation::SENT, AccountInvitation::query()->findOrFail($invitation->id)->status());

        $this->travel(11)->minutes();
        $this->post(route('password.email'), ['matric_number' => $user->matric_number]);
        $this->assertSame(AccountInvitation::WAITING, AccountInvitation::query()->findOrFail($invitation->id)->status());
        $this->get(route('invitation.show', $old))->assertStatus(410);
    }

    #[Test]
    public function signing_in_another_way_uses_up_the_invitation(): void
    {
        $user = User::factory()->create(['password' => 'Str0ng!pass']);
        Invitations::queue($user, $this->admin);
        $link = $this->send()[$user->matric_number];

        $this->post(route('login.store'), ['matric_number' => $user->matric_number, 'password' => 'Str0ng!pass'])->assertRedirect();

        $this->assertSame(AccountInvitation::ACCEPTED, $user->invitation?->fresh()?->status());
        auth()->logout();
        $this->get(route('invitation.show', $link))->assertStatus(410);
    }

    #[Test]
    public function a_suspended_account_cannot_use_its_invitation(): void
    {
        $user = User::factory()->create();
        Invitations::queue($user, $this->admin);
        $link = $this->send()[$user->matric_number];

        $user->forceFill(['status' => UserStatus::Suspended])->save();
        $this->get(route('invitation.show', $link))->assertStatus(410);
    }
}
