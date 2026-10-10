<?php

namespace Tests\Feature\Admin;

use App\Enums\BookStatus;
use App\Enums\Role;
use App\Models\Book;
use App\Models\Department;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->role(Role::Admin)->create();
    }

    #[Test]
    public function only_admins_reach_the_admin_pages(): void
    {
        $pages = ['admin.dashboard', 'admin.users.index', 'admin.accounts.index', 'admin.books.index', 'admin.reports', 'admin.audit', 'admin.settings'];

        foreach ([Role::Student, Role::CourseRep, Role::Governor] as $role) {
            $this->actingAs(User::factory()->role($role)->create());
            foreach ($pages as $page) {
                $this->get(route($page))->assertForbidden();
            }
        }

        $this->actingAs($this->admin);
        foreach ($pages as $page) {
            $this->get(route($page))->assertOk()->assertSee('aria-label="Admin"', false);
        }
    }

    #[Test]
    public function the_dashboard_counts_what_needs_attention(): void
    {
        Book::factory()->count(2)->status(BookStatus::Pending)->create();
        Book::factory()->approved()->create();

        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertSee('2 waiting')
            ->assertSee('1 book</strong>', false)
            ->assertSee('Admin panel');
    }

    #[Test]
    public function books_can_be_found_and_deleted_for_good(): void
    {
        $book = Book::factory()->approved()->create(['title' => 'Compiler Construction Notes']);
        Book::factory()->approved()->create(['title' => 'Networking Basics']);

        $this->actingAs($this->admin)->get(route('admin.books.index', ['q' => 'compiler']))
            ->assertSee('Compiler Construction Notes')
            ->assertDontSee('Networking Basics');

        $this->delete(route('admin.books.destroy', $book))->assertRedirect();
        $this->assertSoftDeleted($book);
        $this->assertDatabaseHas('audit_logs', ['action' => 'book_deleted', 'subject_id' => $book->id]);
    }

    #[Test]
    public function the_audit_log_filters_by_action_and_person(): void
    {
        $student = User::factory()->create(['fullname' => 'Kemi Adeyemi']);
        $this->actingAs($student);
        Audit::record('profile_updated');
        Audit::record('password_changed');
        // A random name could contain "kemi" too (Akemi).
        $this->admin->forceFill(['fullname' => 'Tunde Bakare'])->save();
        $this->actingAs($this->admin);
        Audit::record('settings_updated');

        $this->get(route('admin.audit', ['who' => 'kemi']))
            ->assertOk()
            ->assertSee('Updated profile')
            ->assertSee('Changed password')
            ->assertDontSee('audit-action">Changed settings', false);

        $this->get(route('admin.audit', ['action' => 'password_changed']))
            ->assertSee('Kemi Adeyemi')
            ->assertDontSee('audit-action">Updated profile', false);
    }

    #[Test]
    public function the_audit_log_shows_a_page_at_a_time_grouped_by_day(): void
    {
        $this->actingAs($this->admin);
        $this->travelTo(now()->setTimezone(config('app.display_timezone'))->setTime(12, 0));
        Audit::record('settings_updated');
        $this->travel(-1)->days();
        Audit::record('profile_updated');
        $this->travelBack();

        for ($i = 0; $i < 30; $i++) {
            Audit::record('login');
        }

        $this->get(route('admin.audit'))->assertOk()
            ->assertSee('Showing <strong>1–25</strong> of <strong>32</strong> entries', false)
            ->assertSee('Page 1 of 2')
            ->assertSee('Today')
            ->assertSee('Export CSV');

        $this->get(route('admin.audit', ['page' => 2]))->assertOk()
            ->assertSee('Showing <strong>26–32</strong>', false)
            ->assertSee('Yesterday')
            ->assertSee('audit-action">Updated profile', false);
    }

    #[Test]
    public function the_audit_log_exports_the_filtered_entries_as_csv(): void
    {
        $student = User::factory()->create(['fullname' => 'Kemi Adeyemi', 'matric_number' => 'F/ND/24/0000777']);
        $this->actingAs($student);
        Audit::record('password_changed');
        Audit::record('profile_updated');
        // A name a spreadsheet would run as a formula.
        $this->actingAs(User::factory()->create(['fullname' => '=HYPERLINK("http://evil")']));
        Audit::record('login');
        $this->actingAs($this->admin);
        Audit::record('settings_updated');

        $response = $this->get(route('admin.audit.export', ['who' => 'kemi']))->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('audit-log-', (string) $response->headers->get('Content-Disposition'));

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Date,Time,Action,"Action code",Name,"Matric number",Subject,Details,"IP address"', $csv);
        $this->assertStringContainsString('"Changed password",password_changed,"Kemi Adeyemi",F/ND/24/0000777', $csv);
        $this->assertStringNotContainsString('Changed settings', $csv);
        $this->assertStringNotContainsString('HYPERLINK', $csv);
        // Newest first.
        $this->assertLessThan(strpos($csv, 'Changed password'), strpos($csv, 'Updated profile'));

        // Everything, with the formula kept as text.
        $all = $this->get(route('admin.audit.export'))->streamedContent();
        $this->assertStringContainsString('"\'=HYPERLINK(""http://evil"")"', $all);

        // The export itself is logged, and only admins can export.
        $this->assertDatabaseHas('audit_logs', ['action' => 'audit_exported', 'user_id' => $this->admin->id]);
        $this->actingAs($student)->get(route('admin.audit.export'))->assertForbidden();
    }

    #[Test]
    public function reports_count_by_month_with_the_year(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 15));
        Book::factory()->approved()->create()->forceFill(['approved_at' => now()->subMonths(2)])->save();
        Book::factory()->approved()->create()->forceFill(['approved_at' => now()->subYear()])->save();

        $this->actingAs($this->admin)->get(route('admin.reports'))
            ->assertOk()
            ->assertSee('Aug 2026')
            ->assertSee('Nov 2025')
            ->assertDontSee('Oct 2025');
    }

    #[Test]
    public function settings_are_saved_and_applied(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.update'), [
            'site_name' => 'NACOS Yaba',
            'academic_session' => '2026/2027',
            'pdf_max_mb' => 20,
            'uploads_per_day' => 3,
            'uploads_pending' => 9,
        ])->assertSessionHasNoErrors();

        $this->assertSame(20 * 1024, config('uploads.pdf_max_kb'));
        $this->assertSame(3, config('uploads.per_day'));
        $this->assertSame('NACOS Yaba', config('app.name'));
        $this->get(route('admin.dashboard'))->assertSee('Academic session 2026/2027');

        $this->put(route('admin.settings.update'), ['site_name' => 'x', 'academic_session' => '26/27', 'pdf_max_mb' => 500, 'uploads_per_day' => 1, 'uploads_pending' => 1])
            ->assertSessionHasErrors(['academic_session', 'pdf_max_mb']);
    }

    #[Test]
    public function closing_a_department_clears_the_library_cache_but_one_stays_open(): void
    {
        Department::query()->update(['is_active' => false]);
        $open = Department::factory()->create(['is_active' => true]);
        $closed = Department::factory()->create(['is_active' => false]);
        Cache::put('catalog:active-departments', [$open->id], 300);

        $this->actingAs($this->admin)->put(route('admin.settings.department', $closed), ['open' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue(Department::findOrFail($closed->id)->is_active);
        $this->assertFalse(Cache::has('catalog:active-departments'));

        $this->put(route('admin.settings.department', $closed), ['open' => '0']);
        $this->put(route('admin.settings.department', $open), ['open' => '0'])->assertSessionHasErrors('department');
        $this->assertTrue(Department::findOrFail($open->id)->is_active);
    }
}
