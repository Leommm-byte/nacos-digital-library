<?php

namespace Tests\Feature\Admin;

use App\Enums\Level;
use App\Enums\Programme;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->student = User::factory()->create(['fullname' => 'Tunde Bello', 'matric_number' => 'F/ND/24/1234567', 'level' => Level::ND1]);
    }

    #[Test]
    public function users_are_found_by_name_matric_or_filters(): void
    {
        User::factory()->role(Role::Governor)->create(['fullname' => 'Gbenga Governor']);

        $this->actingAs($this->admin)->get(route('admin.users.index', ['q' => 'f/nd/24/1234567']))
            ->assertSee('Tunde Bello')->assertDontSee('Gbenga Governor');

        $this->get(route('admin.users.index', ['role' => 'governor']))
            ->assertSee('Gbenga Governor')->assertDontSee('Tunde Bello');

        $this->get(route('admin.users.show', $this->student))->assertOk()->assertSee('Tunde Bello')->assertSee('Not on the roll');
    }

    #[Test]
    public function an_admin_changes_roles_and_suspends_other_people(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.users.role', $this->student), ['role' => 'governor'])
            ->assertSessionHas('status', 'Tunde is now a governor.');
        $this->assertSame(Role::Governor, User::findOrFail($this->student->id)->role);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user_role_changed', 'subject_id' => $this->student->id]);

        $this->put(route('admin.users.status', $this->student), ['status' => 'suspended']);
        $this->assertSame(UserStatus::Suspended, User::findOrFail($this->student->id)->status);

        // Suspended people are signed out on their next request.
        $this->actingAs(User::findOrFail($this->student->id))->get('/')->assertRedirect(route('login'));

        $this->actingAs($this->admin)->put(route('admin.users.status', $this->student), ['status' => 'active']);
        $this->assertSame(UserStatus::Active, User::findOrFail($this->student->id)->status);
    }

    #[Test]
    public function admins_cannot_change_their_own_account_here(): void
    {
        $this->actingAs($this->admin);

        $this->put(route('admin.users.role', $this->admin), ['role' => 'student'])->assertForbidden();
        $this->put(route('admin.users.status', $this->admin), ['status' => 'suspended'])->assertForbidden();
        $this->post(route('admin.users.password', $this->admin))->assertForbidden();

        $this->get(route('admin.users.show', $this->admin))->assertSee('This is your own account')->assertDontSee('Save role');
        $this->assertSame(Role::Admin, User::findOrFail($this->admin->id)->role);
    }

    #[Test]
    public function the_class_can_be_corrected(): void
    {
        $department = Department::factory()->create();

        $this->actingAs($this->admin)->put(route('admin.users.class', $this->student), [
            'level' => 'ND2', 'programme' => 'part_time', 'department_id' => $department->id,
        ])->assertSessionHasNoErrors();

        $user = User::findOrFail($this->student->id);
        $this->assertSame(Level::ND2, $user->level);
        $this->assertSame(Programme::PartTime, $user->programme);
        $this->assertSame($department->id, $user->department_id);
    }

    #[Test]
    public function a_new_password_slip_works_once_and_must_be_changed(): void
    {
        $this->actingAs($this->admin)->post(route('admin.users.password', $this->student));

        $slip = session('slip');
        $this->assertIsArray($slip);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/', $slip['password']);

        $user = User::findOrFail($this->student->id);
        $this->assertTrue(Hash::check($slip['password'], $user->password));
        $this->assertTrue($user->must_change_password);

        $this->get(route('admin.users.show', $this->student))->assertSee($slip['password'])->assertSee('Print slip');
        $this->get(route('admin.users.show', $this->student))->assertDontSee($slip['password']);
    }

    #[Test]
    public function two_step_can_be_turned_off_for_someone_who_lost_their_phone(): void
    {
        $this->student->forceFill(['two_factor_secret' => 'SECRET', 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => ['a']])->save();

        $this->actingAs($this->admin)->delete(route('admin.users.two-factor', $this->student))->assertSessionHasNoErrors();

        $this->assertFalse(User::findOrFail($this->student->id)->hasTwoFactorEnabled());
        $this->assertDatabaseHas('audit_logs', ['action' => 'user_two_factor_reset', 'user_id' => $this->admin->id]);
    }
}
