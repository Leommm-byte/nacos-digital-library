<?php

namespace Tests\Feature\Auth;

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private const ERROR = "That matric number and password don't match.";

    #[Test]
    public function the_login_page_renders(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Matric number')
            ->assertSee('data-password-toggle', false);
    }

    #[Test]
    public function students_log_in_with_their_matric_number_in_any_case(): void
    {
        $user = User::factory()->create(['matric_number' => 'F/ND/24/1234567']);

        $this->post('/login', ['matric_number' => ' f/nd/24/1234567 ', 'password' => 'Password1!'])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()?->last_login_at);
        $this->assertSame('127.0.0.1', $user->fresh()?->last_login_ip);
        $this->assertDatabaseHas('audit_logs', ['action' => 'login', 'user_id' => $user->id]);
    }

    #[Test]
    public function a_wrong_password_and_an_unknown_matric_number_get_the_same_answer(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['matric_number' => $user->matric_number, 'password' => 'wrong'])
            ->assertSessionHasErrors(['matric_number' => self::ERROR]);

        $this->post('/login', ['matric_number' => 'F/ND/24/7654321', 'password' => 'wrong'])
            ->assertSessionHasErrors(['matric_number' => self::ERROR]);

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'login_failed', 'subject_id' => $user->id, 'user_id' => null]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'login_failed', 'subject_id' => null]);
    }

    #[Test]
    public function repeated_failures_slow_down_only_the_attacker(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < LoginRequest::MAX_ATTEMPTS_PER_ACCOUNT; $i++) {
            $this->post('/login', ['matric_number' => $user->matric_number, 'password' => 'guess'.$i]);
        }

        // The attacker's IP is now blocked for this account, even with the
        // right password...
        $this->post('/login', ['matric_number' => $user->matric_number, 'password' => 'Password1!'])
            ->assertSessionHasErrors('matric_number');
        $this->assertStringStartsWith('Too many attempts.', (string) session('errors')?->first('matric_number'));
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'login_throttled']);

        // ...but the owner, on their own connection, still gets in.
        $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])
            ->post('/login', ['matric_number' => $user->matric_number, 'password' => 'Password1!'])
            ->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function other_students_on_the_same_network_are_not_blocked(): void
    {
        $target = User::factory()->create();
        $classmate = User::factory()->create();

        for ($i = 0; $i < LoginRequest::MAX_ATTEMPTS_PER_ACCOUNT + 2; $i++) {
            $this->post('/login', ['matric_number' => $target->matric_number, 'password' => 'guess'.$i]);
        }

        $this->post('/login', ['matric_number' => $classmate->matric_number, 'password' => 'Password1!'])
            ->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($classmate);
    }

    #[Test]
    public function a_flood_of_failures_from_one_ip_is_capped(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < LoginRequest::MAX_ATTEMPTS_PER_IP; $i++) {
            RateLimiter::hit('login-ip:127.0.0.1', 600);
        }

        $this->post('/login', ['matric_number' => $user->matric_number, 'password' => 'Password1!'])
            ->assertSessionHasErrors('matric_number');
        $this->assertGuest();
    }

    #[Test]
    public function a_successful_login_clears_the_failure_count(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < LoginRequest::MAX_ATTEMPTS_PER_ACCOUNT - 1; $i++) {
            $this->post('/login', ['matric_number' => $user->matric_number, 'password' => 'guess'.$i]);
        }
        $this->post('/login', ['matric_number' => $user->matric_number, 'password' => 'Password1!']);
        $this->post('/logout');

        $this->post('/login', ['matric_number' => $user->matric_number, 'password' => 'wrong']);
        $this->post('/login', ['matric_number' => $user->matric_number, 'password' => 'Password1!'])
            ->assertRedirect(route('home'));
    }

    #[Test]
    public function suspended_accounts_cannot_log_in(): void
    {
        $user = User::factory()->suspended()->create();

        $this->post('/login', ['matric_number' => $user->matric_number, 'password' => 'Password1!'])
            ->assertSessionHasErrors(['matric_number' => 'This account has been suspended. Contact a NACOS admin if you think this is a mistake.']);

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'login_blocked_suspended', 'subject_id' => $user->id]);
    }

    #[Test]
    public function suspension_is_only_revealed_to_someone_with_the_right_password(): void
    {
        $user = User::factory()->suspended()->create();

        $this->post('/login', ['matric_number' => $user->matric_number, 'password' => 'wrong'])
            ->assertSessionHasErrors(['matric_number' => self::ERROR]);
    }

    #[Test]
    public function signed_in_users_are_sent_home_from_the_login_and_signup_pages(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/login')->assertRedirect(route('home'));
        $this->get('/signup')->assertRedirect(route('home'));
    }

    #[Test]
    public function logging_out_ends_the_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'logout', 'user_id' => $user->id]);
    }

    #[Test]
    public function logout_is_not_a_link_that_can_be_triggered_by_get(): void
    {
        $this->actingAs(User::factory()->create())->get('/logout')->assertMethodNotAllowed();

        $this->assertAuthenticated();
    }
}
