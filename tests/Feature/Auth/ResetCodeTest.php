<?php

namespace Tests\Feature\Auth;

use App\Enums\Level;
use App\Enums\Role;
use App\Http\Controllers\Auth\ResetCodeController;
use App\Models\Department;
use App\Models\PasswordResetCode;
use App\Models\User;
use App\Support\OneTimeCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResetCodeTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->department = Department::factory()->create();
    }

    private function member(Role $role, Level $level = Level::ND1, ?Department $department = null): User
    {
        return User::factory()->role($role)->create([
            'department_id' => ($department ?? $this->department)->id,
            'level' => $level,
        ]);
    }

    private function giveCode(User $user, string $code = 'ABCD-EFGH', int $minutes = 30): void
    {
        $user->passwordResetCodes()->create([
            'code_hash' => OneTimeCode::hash($code),
            'expires_at' => now()->addMinutes($minutes),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function redeem(User $user, string $code = 'ABCD-EFGH'): array
    {
        return [
            'matric_number' => $user->matric_number,
            'code' => $code,
            'password' => 'N3w!password',
            'password_confirmation' => 'N3w!password',
        ];
    }

    #[Test]
    public function students_cannot_open_the_reset_code_page(): void
    {
        $this->actingAs($this->member(Role::Student))->get('/reset-codes')->assertForbidden();
        $this->actingAs($this->member(Role::CourseRep))->get('/reset-codes')->assertOk();
    }

    #[Test]
    public function a_course_rep_issues_a_code_for_a_classmate(): void
    {
        $rep = $this->member(Role::CourseRep);
        $student = $this->member(Role::Student);

        $response = $this->actingAs($rep)->post('/reset-codes', ['matric_number' => strtolower($student->matric_number)]);

        $response->assertRedirect(route('reset-codes.create'))->assertSessionHas('issued_code');
        $issued = session('issued_code');
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $issued['code']);

        $stored = PasswordResetCode::where('user_id', $student->id)->sole();
        $this->assertSame($rep->id, $stored->issued_by);
        $this->assertTrue(OneTimeCode::matches($issued['code'], $stored->code_hash));
        $this->assertDatabaseMissing('password_reset_codes', ['code_hash' => $issued['code']]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'password_reset_code_issued', 'subject_id' => $student->id, 'user_id' => $rep->id]);

        $this->get('/reset-codes')->assertSee($issued['code']);
    }

    #[Test]
    public function course_reps_are_limited_to_students_in_their_own_class(): void
    {
        $rep = $this->member(Role::CourseRep);
        $otherLevel = $this->member(Role::Student, Level::ND2);
        $otherDepartment = $this->member(Role::Student, Level::ND1, Department::factory()->create());
        $otherRep = $this->member(Role::CourseRep);

        foreach ([$otherLevel, $otherDepartment, $otherRep, $rep] as $target) {
            $this->actingAs($rep)
                ->post('/reset-codes', ['matric_number' => $target->matric_number])
                ->assertSessionHasErrors('matric_number');
        }

        $this->assertDatabaseCount('password_reset_codes', 0);
    }

    #[Test]
    public function admins_can_issue_codes_for_anyone_else(): void
    {
        $admin = $this->member(Role::Admin);
        $governor = $this->member(Role::Governor, Level::HND2, Department::factory()->create());

        $this->actingAs($admin)
            ->post('/reset-codes', ['matric_number' => $governor->matric_number])
            ->assertSessionHas('issued_code');
    }

    #[Test]
    public function an_unknown_matric_number_is_reported(): void
    {
        $this->actingAs($this->member(Role::Admin))
            ->post('/reset-codes', ['matric_number' => 'F/ND/24/9999999'])
            ->assertSessionHasErrors(['matric_number' => 'No account has that matric number.']);
    }

    #[Test]
    public function a_code_resets_the_password_once(): void
    {
        $student = $this->member(Role::Student);
        $student->forceFill(['must_change_password' => true])->save();
        $this->giveCode($student);

        $this->post('/reset-with-code', $this->redeem($student, 'abcd efgh'))->assertRedirect(route('login'));

        $student->refresh();
        $this->assertTrue(Hash::check('N3w!password', $student->password));
        $this->assertFalse($student->must_change_password);
        $this->assertNotNull(PasswordResetCode::sole()->used_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'password_reset', 'user_id' => $student->id]);

        $this->post('/reset-with-code', $this->redeem($student))->assertSessionHasErrors('code');
    }

    #[Test]
    public function codes_expire(): void
    {
        $student = $this->member(Role::Student);
        $this->giveCode($student);

        $this->travel(PasswordResetCode::LIFETIME_MINUTES + 1)->minutes();

        $this->post('/reset-with-code', $this->redeem($student))->assertSessionHasErrors('code');
        $this->assertTrue(Hash::check('Password1!', (string) $student->fresh()?->password));
    }

    #[Test]
    public function issuing_a_new_code_cancels_the_old_one(): void
    {
        $rep = $this->member(Role::CourseRep);
        $student = $this->member(Role::Student);

        $this->actingAs($rep)->post('/reset-codes', ['matric_number' => $student->matric_number]);
        $first = session('issued_code')['code'];
        $this->post('/reset-codes', ['matric_number' => $student->matric_number]);
        $second = session('issued_code')['code'];
        $this->post('/logout');

        $this->post('/reset-with-code', $this->redeem($student, $first))->assertSessionHasErrors('code');
        $this->post('/reset-with-code', $this->redeem($student, $second))->assertRedirect(route('login'));
    }

    #[Test]
    public function guessing_codes_is_rate_limited(): void
    {
        $student = $this->member(Role::Student);
        $this->giveCode($student);

        for ($i = 0; $i < ResetCodeController::MAX_ATTEMPTS_PER_ACCOUNT; $i++) {
            $this->post('/reset-with-code', $this->redeem($student, 'WRNG-CD'.$i));
        }

        $this->post('/reset-with-code', $this->redeem($student))
            ->assertSessionHasErrors(['code' => 'Too many attempts. Try again in a few minutes.']);
        $this->assertTrue(Hash::check('Password1!', (string) $student->fresh()?->password));
    }
}
