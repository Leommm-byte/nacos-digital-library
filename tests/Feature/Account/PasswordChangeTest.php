<?php

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'current_password' => 'Password1!',
            'password' => 'N3w!password',
            'password_confirmation' => 'N3w!password',
        ], $overrides);
    }

    #[Test]
    public function the_settings_page_has_a_password_form(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/settings')
            ->assertOk()
            ->assertSee(route('settings.password'), false);
    }

    #[Test]
    public function students_change_their_password_and_stay_signed_in(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['remember_token' => 'old-token'])->save();

        $this->actingAs($user)
            ->put('/settings/password', $this->form())
            ->assertRedirect(route('settings'))
            ->assertSessionHas('status');

        $user->refresh();
        $this->assertTrue(Hash::check('N3w!password', $user->password));
        $this->assertNotSame('old-token', $user->getRememberToken());
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', ['action' => 'password_changed', 'user_id' => $user->id]);
    }

    #[Test]
    public function the_current_password_is_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put('/settings/password', $this->form(['current_password' => 'wrong']))
            ->assertSessionHasErrorsIn('password', 'current_password');

        $this->assertTrue(Hash::check('Password1!', (string) $user->fresh()?->password));
    }

    #[Test]
    public function the_new_password_must_be_strong_confirmed_and_different(): void
    {
        $this->actingAs(User::factory()->create());

        $this->put('/settings/password', $this->form(['password' => 'weakpass', 'password_confirmation' => 'weakpass']))
            ->assertSessionHasErrorsIn('password', 'password');

        $this->put('/settings/password', $this->form(['password_confirmation' => 'Different1!']))
            ->assertSessionHasErrorsIn('password', 'password');

        $this->put('/settings/password', $this->form(['password' => 'Password1!', 'password_confirmation' => 'Password1!']))
            ->assertSessionHasErrorsIn('password', 'password');
    }
}
