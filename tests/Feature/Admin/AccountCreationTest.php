<?php

namespace Tests\Feature\Admin;

use App\Enums\Level;
use App\Enums\Programme;
use App\Enums\Role;
use App\Http\Controllers\Admin\AccountController;
use App\Models\Department;
use App\Models\RollEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AccountCreationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->department = Department::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->for($this->department)->role(Role::Admin)->create();
    }

    #[Test]
    public function accounts_are_created_for_a_class_with_printable_slips(): void
    {
        RollEntry::create(['matric_number' => 'F/HD/24/0000001', 'fullname' => 'OBI Adaeze', 'email' => 'ada@example.com', 'programme' => 'full_time', 'level' => 'HND1']);
        RollEntry::create(['matric_number' => 'F/HD/24/0000002', 'fullname' => 'BELLO Tunde', 'programme' => 'full_time', 'level' => 'HND1']);
        // No name on the roll: skipped.
        RollEntry::create(['matric_number' => 'F/HD/24/0000003', 'programme' => 'full_time', 'level' => 'HND1']);
        // Another class: not ticked.
        RollEntry::create(['matric_number' => 'P/ND/24/0000004', 'fullname' => 'Part Timer', 'programme' => 'part_time', 'level' => 'ND1']);
        // Already has an account.
        User::factory()->create(['matric_number' => 'F/HD/24/0000005']);
        RollEntry::create(['matric_number' => 'F/HD/24/0000005', 'fullname' => 'Has Account', 'programme' => 'full_time', 'level' => 'HND1']);

        $this->actingAs($this->admin)->get(route('admin.accounts.index'))->assertOk()->assertSee('HND1 Full-time')->assertSee('1 without a name');

        $this->post(route('admin.accounts.store'), ['classes' => ['full_time|HND1'], 'department_id' => $this->department->id])
            ->assertRedirect(route('admin.accounts.slips'));

        $ada = User::where('matric_number', 'F/HD/24/0000001')->firstOrFail();
        $this->assertSame('OBI Adaeze', $ada->fullname);
        $this->assertSame('ada@example.com', $ada->email);
        $this->assertNull($ada->email_verified_at);
        $this->assertSame(Level::HND1, $ada->level);
        $this->assertSame(Programme::FullTime, $ada->programme);
        $this->assertSame(Role::Student, $ada->role);
        $this->assertTrue($ada->must_change_password);
        $this->assertSame(2024, $ada->entry_year);
        $this->assertDatabaseMissing('users', ['matric_number' => 'F/HD/24/0000003']);
        $this->assertDatabaseMissing('users', ['matric_number' => 'P/ND/24/0000004']);

        // The slips show each password once; it works and isn't the surname.
        /** @var list<array{name: string, matric: string, password: string, class: string}> $slips */
        $slips = session('slips')['slips'];
        $this->assertCount(2, $slips);
        $adaSlip = collect($slips)->firstWhere('matric', 'F/HD/24/0000001');
        $this->assertTrue(Hash::check($adaSlip['password'], $ada->password));
        $this->assertStringNotContainsStringIgnoringCase('obi', $adaSlip['password']);

        $this->get(route('admin.accounts.slips'))->assertOk()->assertSee($adaSlip['password'])->assertSee('Print slips');
        $this->get(route('admin.accounts.slips'))->assertRedirect(route('admin.accounts.index'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'accounts_created', 'user_id' => $this->admin->id]);
    }

    #[Test]
    public function a_new_account_must_change_its_password_first(): void
    {
        RollEntry::create(['matric_number' => 'F/ND/24/0000009', 'fullname' => 'New Student', 'programme' => 'full_time', 'level' => 'ND1']);
        $this->actingAs($this->admin)->post(route('admin.accounts.store'), ['classes' => ['full_time|ND1'], 'department_id' => $this->department->id]);
        $password = session('slips')['slips'][0]['password'];

        auth()->logout();
        $this->post(route('login'), ['matric_number' => 'F/ND/24/0000009', 'password' => $password]);
        $this->get(route('library.index'))->assertRedirect(route('password.change'));
    }

    #[Test]
    public function large_classes_are_done_in_batches_and_emails_are_not_reused(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $rows = [];
        for ($i = 1; $i <= AccountController::BATCH + 5; $i++) {
            $rows[] = ['matric_number' => sprintf('F/ND/24/%07d', $i), 'fullname' => "Student {$i}", 'email' => $i <= 2 ? 'taken@example.com' : null, 'programme' => 'full_time', 'level' => 'ND2', 'created_at' => now(), 'updated_at' => now()];
        }
        RollEntry::insert($rows);

        $this->actingAs($this->admin)->post(route('admin.accounts.store'), ['classes' => ['full_time|ND2'], 'department_id' => $this->department->id]);

        $this->assertSame(5, session('slips')['remaining']);
        $this->assertSame(1, User::where('email', 'taken@example.com')->count());

        $this->post(route('admin.accounts.store'), ['classes' => ['full_time|ND2'], 'department_id' => $this->department->id]);
        $this->assertSame(0, session('slips')['remaining']);

        $this->post(route('admin.accounts.store'), ['classes' => ['full_time|ND2'], 'department_id' => $this->department->id])
            ->assertSessionHasErrors('classes');
    }

    #[Test]
    public function only_open_departments_and_real_classes_are_accepted(): void
    {
        $closed = Department::factory()->create(['is_active' => false]);

        $this->actingAs($this->admin)
            ->post(route('admin.accounts.store'), ['classes' => ['full_time|ND1'], 'department_id' => $closed->id])
            ->assertSessionHasErrors('department_id');

        $this->post(route('admin.accounts.store'), ['classes' => ["x' or 1=1"], 'department_id' => $this->department->id])
            ->assertSessionHasErrors('classes.0');
    }
}
