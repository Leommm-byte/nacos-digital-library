<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AccountStateTest extends TestCase
{
    use RefreshDatabase;

    private function temporaryPasswordUser(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['must_change_password' => true])->save();

        return $user;
    }

    #[Test]
    public function a_temporary_password_must_be_changed_before_anything_else(): void
    {
        $this->actingAs($this->temporaryPasswordUser());

        $this->get('/')->assertRedirect(route('password.change'));
        $this->get('/styleguide')->assertRedirect(route('password.change'));
        $this->get('/password/change')->assertOk()->assertSee('Choose a new password');
    }

    #[Test]
    public function logging_in_with_a_temporary_password_leads_to_the_change_page(): void
    {
        $user = $this->temporaryPasswordUser();

        $this->post('/login', ['matric_number' => $user->matric_number, 'password' => 'Password1!'])
            ->assertRedirect(route('home'));

        $this->get('/')->assertRedirect(route('password.change'));
    }

    #[Test]
    public function changing_the_temporary_password_unlocks_the_account(): void
    {
        $user = $this->temporaryPasswordUser();

        $this->actingAs($user)
            ->put('/password/change', ['password' => 'N3w!password', 'password_confirmation' => 'N3w!password'])
            ->assertRedirect(route('home'));

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('N3w!password', $user->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'password_changed', 'user_id' => $user->id]);
        $this->get('/')->assertOk();
    }

    #[Test]
    public function the_new_password_must_differ_from_the_temporary_one(): void
    {
        $this->actingAs($this->temporaryPasswordUser())
            ->put('/password/change', ['password' => 'Password1!', 'password_confirmation' => 'Password1!'])
            ->assertSessionHasErrors('password');
    }

    #[Test]
    public function the_new_password_must_be_strong(): void
    {
        $this->actingAs($this->temporaryPasswordUser())
            ->put('/password/change', ['password' => 'weakpass', 'password_confirmation' => 'weakpass'])
            ->assertSessionHasErrors('password');
    }

    #[Test]
    public function users_with_a_temporary_password_can_still_log_out(): void
    {
        $this->actingAs($this->temporaryPasswordUser())
            ->post('/logout')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[Test]
    public function the_change_page_is_only_for_temporary_passwords(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/password/change')->assertRedirect(route('home'));
        $this->actingAs($user)
            ->put('/password/change', ['password' => 'N3w!password', 'password_confirmation' => 'N3w!password'])
            ->assertRedirect(route('home'));

        $this->assertTrue(Hash::check('Password1!', (string) $user->fresh()?->password));
    }

    #[Test]
    public function guests_cannot_reach_the_change_page(): void
    {
        $this->get('/password/change')->assertRedirect(route('login'));
    }

    #[Test]
    public function suspending_an_account_ends_its_current_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/')->assertOk();

        $user->forceFill(['status' => UserStatus::Suspended])->save();

        $this->get('/')
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'session_ended_suspended', 'subject_id' => $user->id]);
    }
}
