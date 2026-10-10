<?php

namespace Tests\Feature\Auth;

use App\Enums\Level;
use App\Enums\Programme;
use App\Enums\Role;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SignupTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->department = Department::factory()->create(['name' => 'Computer Science']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'fullname' => '  Ada   Obi ',
            'matric_number' => 'f/nd/24/1234567',
            'department_id' => $this->department->id,
            'level' => 'ND1',
            'programme' => 'full_time',
            'password' => 'Str0ng!pass',
            'password_confirmation' => 'Str0ng!pass',
        ], $overrides);
    }

    #[Test]
    public function the_signup_page_lists_only_active_departments(): void
    {
        Department::factory()->create(['name' => 'Accountancy', 'is_active' => false]);

        $this->get('/signup')
            ->assertOk()
            ->assertSee('Computer Science')
            ->assertDontSee('Accountancy')
            ->assertSee('data-password-rules', false);
    }

    #[Test]
    public function students_can_sign_up_and_are_signed_in(): void
    {
        $this->post('/signup', $this->form())
            ->assertRedirect(route('home'))
            ->assertSessionHas('status', 'Welcome to NACOS YabaTech, Ada!');

        $user = User::where('matric_number', 'F/ND/24/1234567')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('Ada Obi', $user->fullname);
        $this->assertSame(Level::ND1, $user->level);
        $this->assertSame(Programme::FullTime, $user->programme);
        $this->assertSame(Role::Student, $user->fresh()?->role);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user_registered', 'user_id' => $user->id]);
    }

    #[Test]
    public function signup_cannot_choose_a_role_or_status(): void
    {
        $this->post('/signup', $this->form(['role' => 'admin', 'status' => 'active', 'must_change_password' => 1]));

        $user = User::where('matric_number', 'F/ND/24/1234567')->firstOrFail()->fresh();

        $this->assertSame(Role::Student, $user?->role);
    }

    #[Test]
    public function matric_numbers_have_seven_digits_and_a_current_entry_year(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 1));

        foreach (['12345', 'F/ND/24', 'X/ND/24/1234567', 'ND/2019/CS/1234'] as $matric) {
            $this->post('/signup', $this->form(['matric_number' => $matric]))
                ->assertSessionHasErrors(['matric_number' => 'Enter the matric number as printed on the ID card, for example F/ND/24/1234567.']);
        }

        // Exactly seven digits after the year.
        foreach (['F/ND/24/123456', 'F/ND/24/12345678'] as $matric) {
            $this->post('/signup', $this->form(['matric_number' => $matric]))
                ->assertSessionHasErrors(['matric_number' => 'The last part of a matric number has 7 digits, for example F/ND/24/1234567.']);
        }

        // A year that hasn't started, or one from long ago.
        $this->post('/signup', $this->form(['matric_number' => 'F/ND/27/1234567']))
            ->assertSessionHasErrors(['matric_number' => "This matric number's year (2027) hasn't started yet. Check it against the ID card."]);
        $this->post('/signup', $this->form(['matric_number' => 'F/ND/15/1234567']))
            ->assertSessionHasErrors(['matric_number' => 'This matric number is from 2015, too long ago for a current student.']);

        $this->post('/signup', $this->form(['matric_number' => 'f/nd/26/1234567']))
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function a_student_can_say_what_to_call_them_at_signup(): void
    {
        $this->post('/signup', $this->form(['display_name' => ' Ada-Mae ']))
            ->assertRedirect(route('home'))
            ->assertSessionHas('status', 'Welcome to NACOS YabaTech, Ada-Mae!');

        $user = User::where('matric_number', 'F/ND/24/1234567')->firstOrFail();
        $this->assertSame('Ada Obi', $user->fullname);
        $this->assertSame('Ada-Mae', $user->display_name);
    }

    #[Test]
    public function a_matric_number_can_only_be_registered_once(): void
    {
        User::factory()->create(['matric_number' => 'F/ND/24/1234567']);

        $this->post('/signup', $this->form())
            ->assertSessionHasErrors(['matric_number' => 'An account with this matric number already exists. Try logging in instead.']);
    }

    #[Test]
    public function department_level_and_programme_are_validated(): void
    {
        $closed = Department::factory()->create(['is_active' => false]);

        $this->post('/signup', $this->form([
            'department_id' => $closed->id,
            'level' => 'ND9',
            'programme' => 'weekend',
        ]))->assertSessionHasErrors(['department_id', 'level', 'programme']);

        $this->assertGuest();
    }

    #[Test]
    public function passwords_must_be_strong_and_confirmed(): void
    {
        $this->post('/signup', $this->form(['password' => 'password', 'password_confirmation' => 'password']))
            ->assertSessionHasErrors('password');

        $this->post('/signup', $this->form(['password_confirmation' => 'Different1!']))
            ->assertSessionHasErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    #[Test]
    public function the_full_name_needs_at_least_three_characters(): void
    {
        $this->post('/signup', $this->form(['fullname' => ' A ']))
            ->assertSessionHasErrors('fullname');
    }
}
