<?php

namespace Tests\Feature\Account;

use App\Models\Department;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function signup(array $overrides = []): array
    {
        return array_merge([
            'fullname' => 'Ada Obi',
            'matric_number' => 'F/ND/24/1234567',
            'department_id' => Department::factory()->create()->id,
            'level' => 'ND1',
            'programme' => 'full_time',
            'password' => 'Str0ng!pass',
            'password_confirmation' => 'Str0ng!pass',
        ], $overrides);
    }

    #[Test]
    public function an_email_given_at_signup_is_sent_a_verification_link(): void
    {
        $this->post('/signup', $this->signup(['email' => ' Ada@Example.com ']))->assertRedirect(route('home'));

        $user = User::where('matric_number', 'F/ND/24/1234567')->firstOrFail();
        $this->assertSame('ada@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    #[Test]
    public function email_is_optional_at_signup(): void
    {
        $this->post('/signup', $this->signup(['email' => '']))->assertRedirect(route('home'));

        $this->assertNull(User::where('matric_number', 'F/ND/24/1234567')->value('email'));
        Notification::assertNothingSent();
    }

    #[Test]
    public function students_without_a_verified_email_are_reminded_to_add_one(): void
    {
        $this->actingAs(User::factory()->create(['email' => null]))
            ->get('/')
            ->assertSee('Add an email address');

        $this->actingAs(User::factory()->unverified()->create())
            ->get('/')
            ->assertSee('so you can reset your password by email');

        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertDontSee('Add an email address');
    }

    #[Test]
    public function changing_the_email_needs_the_password_and_a_new_verification(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put('/settings/email', ['email' => 'new@example.com', 'current_password' => 'wrong'])
            ->assertSessionHasErrorsIn('email', 'current_password');
        $this->assertNotSame('new@example.com', $user->fresh()?->email);

        $this->put('/settings/email', ['email' => 'New@Example.com', 'current_password' => 'Password1!'])
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('new@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'email_changed', 'user_id' => $user->id]);
    }

    #[Test]
    public function an_email_can_belong_to_only_one_account(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs(User::factory()->create())
            ->put('/settings/email', ['email' => 'taken@example.com', 'current_password' => 'Password1!'])
            ->assertSessionHasErrorsIn('email', 'email');
    }

    #[Test]
    public function the_emailed_link_verifies_the_address(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1((string) $user->email),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect(route('settings'));

        $this->assertTrue($user->fresh()?->hasVerifiedEmail());
    }

    #[Test]
    public function the_verification_link_can_be_sent_again(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->post('/email/verification-notification')->assertSessionHas('status');

        Notification::assertSentTo($user, VerifyEmail::class);
    }
}
