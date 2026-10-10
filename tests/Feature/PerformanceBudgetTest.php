<?php

namespace Tests\Feature;

use App\Enums\BookStatus;
use App\Enums\Level;
use App\Enums\Role;
use App\Models\Announcement;
use App\Models\Book;
use App\Models\Department;
use App\Models\Election;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Performance budget for database work: the busiest pages must run a small,
 * fixed number of queries however much data there is. A page whose count
 * grows with the number of books, uploads or notifications would slow down
 * as the library fills up (N+1 queries).
 *
 * The counts are written to storage/logs/query-budget.txt; CI prints them.
 */
class PerformanceBudgetTest extends TestCase
{
    use RefreshDatabase;

    /** Most queries any of these pages may run, cold cache. */
    private const MAX_QUERIES = 30;

    private Department $department;

    private User $student;

    private User $admin;

    private Book $book;

    private Election $election;

    protected function setUp(): void
    {
        parent::setUp();

        $this->department = Department::factory()->create();
        $this->student = User::factory()->for($this->department)->create(['level' => Level::ND1]);
        $this->admin = User::factory()->for($this->department)->role(Role::Admin)->create();
        $this->book = $this->books(1)[0];
        $this->election = Election::factory()->open()->withBallot()->create();
        $this->addData(2);
    }

    #[Test]
    public function busy_pages_run_a_fixed_number_of_queries(): void
    {
        $pages = [
            'home' => [$this->student, route('home')],
            'library' => [$this->student, route('library.index')],
            'library, ND1 filter' => [$this->student, route('library.index', ['level' => 'ND1'])],
            'book' => [$this->student, route('library.show', $this->book)],
            'reader' => [$this->student, route('books.read', $this->book)],
            'saved' => [$this->student, route('bookmarks.index')],
            'my uploads' => [$this->student, route('uploads.index')],
            'notifications' => [$this->student, route('notifications.index')],
            'announcements' => [$this->student, route('announcements.index')],
            'elections' => [$this->student, route('elections.index')],
            'election' => [$this->student, route('elections.show', $this->election)],
            'assistant' => [$this->student, route('assistant.index')],
            'review queue' => [$this->admin, route('review.index')],
            'admin dashboard' => [$this->admin, route('admin.dashboard')],
            'admin users' => [$this->admin, route('admin.users.index')],
            'admin books' => [$this->admin, route('admin.books.index')],
        ];

        $small = array_map(fn (array $page) => $this->queries(...$page), $pages);

        // Ten times the data: the counts must not follow it.
        $this->addData(20);
        $large = array_map(fn (array $page) => $this->queries(...$page), $pages);

        $report = ['Queries per page (cold cache): small data -> 10x data, budget '.self::MAX_QUERIES];
        foreach ($pages as $name => $page) {
            $report[] = sprintf('  %-22s %3d -> %3d', $name, $small[$name], $large[$name]);
        }
        @file_put_contents(storage_path('logs/query-budget.txt'), implode("\n", $report)."\n");

        foreach ($pages as $name => $page) {
            $this->assertLessThanOrEqual(self::MAX_QUERIES, $large[$name], "{$name} runs too many queries.\n".implode("\n", $report));
            $this->assertLessThanOrEqual($small[$name] + 1, $large[$name], "{$name} runs more queries as data grows (N+1?).\n".implode("\n", $report));
        }
    }

    private function queries(User $user, string $url): int
    {
        Cache::flush();
        $this->actingAs($user);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get($url)->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * @return list<Book>
     */
    private function books(int $count): array
    {
        return Book::factory()->count($count)->for($this->department)->approved()->create(['level' => Level::ND1])->all();
    }

    /**
     * Books, saved books, reading, uploads (some pending review),
     * notifications, announcements and activity, $n of each.
     */
    private function addData(int $n): void
    {
        $books = $this->books($n);

        foreach ($books as $index => $book) {
            $this->student->bookmarks()->attach($book->id, ['created_at' => now()]);
            DB::table('reading_progress')->insert([
                'user_id' => $this->student->id,
                'book_id' => $book->id,
                'current_page' => 3,
                'progress_percent' => 10,
                'last_read_at' => now()->subMinutes($index),
            ]);
            Audit::record('book_uploaded', $book, ['title' => $book->title], $this->student->id);
        }

        Book::factory()->count($n)->for($this->department)->uploadedBy($this->student)->status(BookStatus::Pending)->create();
        Announcement::factory()->count($n)->create();
        User::factory()->count($n)->for($this->department)->create();

        foreach ($books as $book) {
            DB::table('notifications')->insert([
                'id' => (string) str()->uuid(),
                'type' => 'App\\Notifications\\UploadReviewed',
                'notifiable_type' => User::class,
                'notifiable_id' => $this->student->id,
                'data' => json_encode(['book' => $book->public_id, 'title' => $book->title, 'action' => 'approved', 'headline' => 'Approved', 'comment' => null]),
                'read_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
