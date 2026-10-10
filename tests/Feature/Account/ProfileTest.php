<?php

namespace Tests\Feature\Account;

use App\Enums\Level;
use App\Enums\Programme;
use App\Enums\Role;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(User $user, array $overrides = []): array
    {
        return array_merge([
            'fullname' => $user->fullname,
            'department_id' => $user->department_id,
            'level' => $user->level->value,
            'programme' => $user->programme->value,
        ], $overrides);
    }

    #[Test]
    public function the_profile_shows_the_students_details(): void
    {
        $user = User::factory()->create(['fullname' => 'Ada Obi', 'level' => Level::ND2]);

        $this->actingAs($user)
            ->get('/profile')
            ->assertOk()
            ->assertSee('Ada Obi')
            ->assertSee($user->matric_number)
            ->assertSee('ND2')
            ->assertSee('data-preview="fullname"', false);
    }

    #[Test]
    public function guests_are_sent_to_log_in(): void
    {
        $this->get('/profile')->assertRedirect(route('login'));
    }

    #[Test]
    public function the_profile_is_in_the_navigation_for_signed_in_users(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertSee(route('profile.edit'), false)
            ->assertSee('class="tabbar', false);
    }

    #[Test]
    public function students_update_their_details(): void
    {
        $user = User::factory()->create(['level' => Level::ND1]);
        $other = Department::factory()->create();

        $this->actingAs($user)
            ->put('/profile', $this->form($user, [
                'fullname' => '  Ada   Obi ',
                'department_id' => $other->id,
                'level' => 'ND2',
                'programme' => 'part_time',
            ]))
            ->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertSame('Ada Obi', $user->fullname);
        $this->assertSame($other->id, $user->department_id);
        $this->assertSame(Level::ND2, $user->level);
        $this->assertSame(Programme::PartTime, $user->programme);
        $this->assertDatabaseHas('audit_logs', ['action' => 'profile_updated', 'user_id' => $user->id]);
    }

    #[Test]
    public function the_matric_number_role_and_status_cannot_be_changed_here(): void
    {
        $user = User::factory()->create();
        $matric = $user->matric_number;

        $this->actingAs($user)->put('/profile', $this->form($user, [
            'matric_number' => 'F/ND/24/9999999',
            'role' => 'admin',
            'status' => 'active',
        ]));

        $user->refresh();
        $this->assertSame($matric, $user->matric_number);
        $this->assertSame(Role::Student, $user->role);
    }

    #[Test]
    public function details_are_validated(): void
    {
        $user = User::factory()->create();
        $closed = Department::factory()->create(['is_active' => false]);

        $this->actingAs($user)
            ->put('/profile', $this->form($user, [
                'fullname' => 'A',
                'department_id' => $closed->id,
                'level' => 'ND9',
                'programme' => 'weekend',
            ]))
            ->assertSessionHasErrors(['fullname', 'department_id', 'level', 'programme']);
    }

    #[Test]
    public function students_can_stay_in_a_department_that_was_closed(): void
    {
        $closed = Department::factory()->create(['is_active' => false]);
        $user = User::factory()->for($closed)->create();

        $this->actingAs($user)
            ->put('/profile', $this->form($user, ['fullname' => 'New Name']))
            ->assertSessionHasNoErrors();

        $this->assertSame('New Name', $user->fresh()?->fullname);
    }

    #[Test]
    public function course_reps_cannot_move_themselves_to_another_class(): void
    {
        $rep = User::factory()->role(Role::CourseRep)->create(['level' => Level::ND1]);

        $this->actingAs($rep)
            ->put('/profile', $this->form($rep, ['level' => 'HND1']))
            ->assertSessionHasErrors('level');

        $this->put('/profile', $this->form($rep, ['department_id' => Department::factory()->create()->id]))
            ->assertSessionHasErrors('department_id');

        $this->put('/profile', $this->form($rep, ['fullname' => 'Rep Renamed']))->assertSessionHasNoErrors();

        $rep->refresh();
        $this->assertSame(Level::ND1, $rep->level);
        $this->assertSame('Rep Renamed', $rep->fullname);
    }

    #[Test]
    public function a_student_chooses_what_we_call_them(): void
    {
        $user = User::factory()->create(['fullname' => 'Oluwatobiloba Adeyemi']);

        $this->actingAs($user)->get(route('home'))->assertSee('Oluwatobiloba');

        $this->put('/profile', $this->form($user, ['display_name' => '  Tobi  ']))->assertSessionHasNoErrors();
        $this->assertSame('Tobi', $user->fresh()?->display_name);

        // Greetings use it; official places keep the full name.
        $this->get(route('home'))->assertSee('<h1 class="hero-title">Tobi</h1>', false);
        $this->get('/profile')->assertSee('Oluwatobiloba Adeyemi');

        $this->put('/profile', $this->form($user, ['display_name' => 'Tobi <3']))->assertSessionHasErrors('display_name');
        $this->put('/profile', $this->form($user, ['display_name' => str_repeat('a', 31)]))->assertSessionHasErrors('display_name');

        // Emptied, it's the first name again.
        $this->put('/profile', $this->form($user, ['display_name' => '']))->assertSessionHasNoErrors();
        $user = User::findOrFail($user->id);
        $this->assertNull($user->display_name);
        $this->assertSame('Oluwatobiloba', $user->greetingName());
    }

    #[Test]
    public function the_previous_login_is_shown(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['last_login_at' => now()->subDay(), 'last_login_ip' => '10.1.2.3'])->save();

        $this->post('/login', ['matric_number' => $user->matric_number, 'password' => 'Password1!']);

        $this->get('/profile')->assertSee('from 10.1.2.3');
    }
}
