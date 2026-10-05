<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Models\User;
use App\Support\Totp;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep every request in the same 30-second step.
        $this->freezeTime();
    }

    /**
     * @return list<string> the recovery codes
     */
    private function enable(User $user, string $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'): array
    {
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

        return TwoFactor::replaceRecoveryCodes($user);
    }

    private function currentCode(string $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'): string
    {
        return Totp::at($secret, Totp::stepFor(now()->getTimestamp()));
    }

    private function loginWithPassword(User $user): void
    {
        $this->post('/login', ['matric_number' => $user->matric_number, 'password' => 'Password1!'])
            ->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
    }

    #[Test]
    public function turning_it_on_needs_the_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/settings/two-factor', ['current_password' => 'wrong'])
            ->assertSessionHasErrorsIn('twoFactor', 'current_password');

        $this->post('/settings/two-factor', ['current_password' => 'Password1!'])
            ->assertRedirect(route('two-factor.setup'));

        $this->assertFalse($user->fresh()?->hasTwoFactorEnabled());
    }

    #[Test]
    public function the_secret_is_only_saved_once_a_code_from_the_app_works(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/settings/two-factor', ['current_password' => 'Password1!']);
        $secret = session('two_factor.pending_secret');
        $this->assertIsString($secret);

        $this->get('/settings/two-factor/setup')
            ->assertOk()
            ->assertSee(Totp::formatForDisplay($secret))
            ->assertSee('data-qr="otpauth://totp/', false);

        $this->post('/settings/two-factor/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertNull($user->fresh()?->two_factor_secret);

        $this->post('/settings/two-factor/confirm', ['code' => $this->currentCode($secret)])
            ->assertRedirect(route('settings'))
            ->assertSessionHas('recovery_codes');

        $user->refresh();
        $this->assertTrue($user->hasTwoFactorEnabled());
        $this->assertSame($secret, $user->two_factor_secret);
        $this->assertCount(TwoFactor::RECOVERY_CODE_COUNT, session('recovery_codes'));
        $this->assertSame(TwoFactor::RECOVERY_CODE_COUNT, TwoFactor::remainingRecoveryCodes($user));
        $this->assertDatabaseHas('audit_logs', ['action' => 'two_factor_enabled', 'user_id' => $user->id]);

        // Stored encrypted, and recovery codes only as hashes.
        $row = (array) DB::table('users')->where('id', $user->id)->first();
        $this->assertStringNotContainsString($secret, (string) $row['two_factor_secret']);
        $this->assertStringNotContainsString(session('recovery_codes')[0], (string) $row['two_factor_recovery_codes']);
    }

    #[Test]
    public function the_setup_page_needs_a_pending_secret(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/settings/two-factor/setup')
            ->assertRedirect(route('settings'));
    }

    #[Test]
    public function login_asks_for_a_code_after_the_password(): void
    {
        $user = User::factory()->create();
        $this->enable($user);

        $this->loginWithPassword($user);
        $this->get('/login/two-factor')->assertOk()->assertSee('Two-step verification');

        $this->post('/login/two-factor', ['code' => $this->currentCode()])->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', ['action' => 'login', 'user_id' => $user->id]);
    }

    #[Test]
    public function a_wrong_code_does_not_log_in(): void
    {
        $user = User::factory()->create();
        $this->enable($user);

        $this->loginWithPassword($user);
        $this->post('/login/two-factor', ['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'two_factor_failed', 'subject_id' => $user->id]);
    }

    #[Test]
    public function a_code_cannot_be_used_twice(): void
    {
        $user = User::factory()->create();
        $this->enable($user);
        $code = $this->currentCode();

        $this->loginWithPassword($user);
        $this->post('/login/two-factor', ['code' => $code])->assertRedirect(route('home'));
        $this->post('/logout');

        $this->loginWithPassword($user);
        $this->post('/login/two-factor', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    #[Test]
    public function each_recovery_code_works_once(): void
    {
        $user = User::factory()->create();
        $codes = $this->enable($user);

        $this->loginWithPassword($user);
        $this->post('/login/two-factor', ['code' => strtolower($codes[0])])
            ->assertRedirect(route('home'))
            ->assertSessionHas('error');
        $this->assertAuthenticatedAs($user);
        $this->assertSame(TwoFactor::RECOVERY_CODE_COUNT - 1, TwoFactor::remainingRecoveryCodes($user->fresh() ?? $user));
        $this->post('/logout');

        $this->loginWithPassword($user);
        $this->post('/login/two-factor', ['code' => $codes[0]])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    #[Test]
    public function guessing_codes_is_rate_limited(): void
    {
        $user = User::factory()->create();
        $this->enable($user);
        $this->loginWithPassword($user);

        for ($i = 0; $i < TwoFactorChallengeController::MAX_ATTEMPTS; $i++) {
            $this->post('/login/two-factor', ['code' => sprintf('%06d', $i)]);
        }

        $this->post('/login/two-factor', ['code' => $this->currentCode()])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    #[Test]
    public function the_second_step_times_out(): void
    {
        $user = User::factory()->create();
        $this->enable($user);
        $this->loginWithPassword($user);

        $this->travel(LoginController::TWO_FACTOR_TIMEOUT_MINUTES + 1)->minutes();

        $this->post('/login/two-factor', ['code' => $this->currentCode()])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    #[Test]
    public function the_second_step_needs_a_password_first(): void
    {
        $this->get('/login/two-factor')->assertRedirect(route('login'));
        $this->post('/login/two-factor', ['code' => '123456'])->assertRedirect(route('login'));
    }

    #[Test]
    public function an_account_suspended_between_the_steps_cannot_finish_logging_in(): void
    {
        $user = User::factory()->create();
        $this->enable($user);
        $this->loginWithPassword($user);

        User::whereKey($user->id)->update(['status' => 'suspended']);

        $this->post('/login/two-factor', ['code' => $this->currentCode()])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    #[Test]
    public function turning_it_off_needs_a_valid_code(): void
    {
        $user = User::factory()->create();
        $this->enable($user);

        $this->actingAs($user)
            ->delete('/settings/two-factor', ['code' => '000000'])
            ->assertSessionHasErrorsIn('disableTwoFactor', 'code');
        $this->assertTrue($user->fresh()?->hasTwoFactorEnabled());

        $this->delete('/settings/two-factor', ['code' => $this->currentCode()])->assertRedirect(route('settings'));

        $user->refresh();
        $this->assertFalse($user->hasTwoFactorEnabled());
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertDatabaseHas('audit_logs', ['action' => 'two_factor_disabled', 'user_id' => $user->id]);
    }

    #[Test]
    public function new_recovery_codes_replace_the_old_ones(): void
    {
        $user = User::factory()->create();
        $old = $this->enable($user);

        $this->actingAs($user)
            ->post('/settings/two-factor/recovery-codes', ['code' => $old[0]])
            ->assertSessionHas('recovery_codes');
        $new = session('recovery_codes');

        $this->assertEmpty(array_intersect($old, $new));
        $this->assertNull(TwoFactor::verify($user->fresh() ?? $user, $old[1]));
        $this->assertSame('recovery', TwoFactor::verify($user->fresh() ?? $user, $new[0]));
    }

    #[Test]
    public function the_settings_page_shows_the_status(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/settings')->assertOk()->assertSee('Turn on');

        $this->enable($user);
        $this->get('/settings')->assertOk()->assertSee('Recovery codes left');
    }
}
