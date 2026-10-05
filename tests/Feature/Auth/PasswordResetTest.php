<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    #[Test]
    public function the_forgot_password_page_links_to_both_ways_of_resetting(): void
    {
        $this->get('/login')->assertSee(route('password.request'), false);

        $this->get('/forgot-password')
            ->assertOk()
            ->assertSee(route('password.code'), false);
    }

    #[Test]
    public function a_reset_link_is_emailed_to_a_verified_address(): void
    {
        $user = User::factory()->create();

        $this->post('/forgot-password', ['matric_number' => strtolower($user->matric_number)])
            ->assertSessionHas('status', ForgotPasswordController::SENT);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $mail = $notification->toMail($user);

            return str_contains((string) $mail->actionUrl, '/reset-password/');
        });
    }

    #[Test]
    public function nothing_is_sent_without_a_verified_email_and_the_answer_is_the_same(): void
    {
        $unverified = User::factory()->unverified()->create();
        $noEmail = User::factory()->create(['email' => null]);

        foreach ([$unverified->matric_number, $noEmail->matric_number, 'F/ND/24/9999999'] as $matric) {
            $this->post('/forgot-password', ['matric_number' => $matric])
                ->assertSessionHas('status', ForgotPasswordController::SENT);
        }

        Notification::assertNothingSent();
    }

    #[Test]
    public function the_link_resets_the_password_and_signs_out_everywhere(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['must_change_password' => true, 'remember_token' => 'old-token'])->save();
        $token = Password::createToken($user);

        $this->get('/reset-password/'.$token.'?email='.urlencode((string) $user->email))->assertOk();

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'N3w!password',
            'password_confirmation' => 'N3w!password',
        ])->assertRedirect(route('login'));

        $user->refresh();
        $this->assertTrue(Hash::check('N3w!password', $user->password));
        $this->assertFalse($user->must_change_password);
        $this->assertNotSame('old-token', $user->getRememberToken());
        $this->assertDatabaseHas('audit_logs', ['action' => 'password_reset', 'user_id' => $user->id]);
    }

    #[Test]
    public function a_link_works_only_once(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $form = ['token' => $token, 'email' => $user->email, 'password' => 'N3w!password', 'password_confirmation' => 'N3w!password'];

        $this->post('/reset-password', $form)->assertRedirect(route('login'));
        $this->post('/reset-password', [...$form, 'password' => 'An0ther!pass', 'password_confirmation' => 'An0ther!pass'])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('N3w!password', (string) $user->fresh()?->password));
    }

    #[Test]
    public function a_bad_token_is_refused(): void
    {
        $user = User::factory()->create();

        $this->post('/reset-password', [
            'token' => 'not-a-token',
            'email' => $user->email,
            'password' => 'N3w!password',
            'password_confirmation' => 'N3w!password',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('Password1!', (string) $user->fresh()?->password));
    }

    #[Test]
    public function the_new_password_must_be_strong(): void
    {
        $user = User::factory()->create();

        $this->post('/reset-password', [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'weakpass',
            'password_confirmation' => 'weakpass',
        ])->assertSessionHasErrors('password');
    }
}
