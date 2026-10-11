<?php

namespace Tests\Feature;

use App\Enums\Level;
use App\Enums\Role;
use App\Models\Announcement;
use App\Models\Book;
use App\Models\Department;
use App\Models\ReadingProgress;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->department = Department::factory()->create(['name' => 'Computer Science']);
        $this->student = User::factory()->for($this->department)->create(['level' => Level::ND1, 'matric_number' => 'F/ND/24/0000077']);
    }

    private function book(string $title, Level $level = Level::ND1, ?Department $department = null): Book
    {
        return Book::factory()->for($department ?? $this->department)->approved()->create(['title' => $title, 'level' => $level, 'page_count' => 40]);
    }

    private function read(Book $book, int $page, int $percent, bool $finished = false): void
    {
        ReadingProgress::upsert([[
            'user_id' => $this->student->id,
            'book_id' => $book->id,
            'current_page' => $page,
            'progress_percent' => $percent,
            'completed_at' => $finished ? now() : null,
            'last_read_at' => now(),
        ]], ['user_id', 'book_id'], ['current_page', 'progress_percent', 'completed_at', 'last_read_at']);
    }

    #[Test]
    public function continue_reading_shows_unfinished_books_with_their_page(): void
    {
        $this->read($this->book('Half Read'), 20, 50);
        $this->read($this->book('All Done'), 40, 100, finished: true);

        $this->actingAs($this->student)
            ->get('/')
            ->assertSee('Continue reading')
            ->assertSee('Half Read')
            ->assertSee('Page 20 of 40')
            ->assertSee('2 books read')
            ->assertSee('1 finished');
    }

    #[Test]
    public function continue_reading_is_hidden_until_a_book_is_opened(): void
    {
        $this->actingAs($this->student)->get('/')->assertDontSee('Continue reading');
    }

    #[Test]
    public function recommendations_put_the_class_first_then_the_level_and_skip_opened_books(): void
    {
        $elsewhere = Department::factory()->create();
        $this->book('Same Level Elsewhere', Level::ND1, $elsewhere);
        $this->travel(1)->minute();
        $this->book('My Class Book');
        $this->book('Other Level', Level::HND2);
        $opened = $this->book('Already Opened');
        $this->read($opened, 1, 3);

        $response = $this->actingAs($this->student)->get('/');
        $html = (string) $response->getContent();
        $recommended = substr($html, (int) strpos($html, 'Recommended for you'), (int) strpos($html, 'Saved books') - (int) strpos($html, 'Recommended for you'));

        $this->assertStringContainsString('My Class Book', $recommended);
        $this->assertStringContainsString('Same Level Elsewhere', $recommended);
        $this->assertLessThan(strpos($recommended, 'Same Level Elsewhere'), strpos($recommended, 'My Class Book'));
        $this->assertStringNotContainsString('Other Level', $recommended);
        $this->assertStringNotContainsString('Already Opened', $recommended);
    }

    #[Test]
    public function up_to_three_live_announcements_show_newest_first(): void
    {
        Announcement::factory()->create(['title' => 'Oldest live', 'starts_at' => now()->subDays(4)]);
        Announcement::factory()->create(['title' => 'Second', 'starts_at' => now()->subDays(3)]);
        Announcement::factory()->create(['title' => 'Third', 'starts_at' => now()->subDays(2)]);
        Announcement::factory()->create(['title' => 'Newest', 'starts_at' => now()->subDay()]);
        Announcement::factory()->create(['title' => 'A Draft', 'is_published' => false]);
        Announcement::factory()->create(['title' => 'Has Ended', 'ends_at' => now()->subMinute()]);
        Announcement::factory()->create(['title' => 'Not Yet', 'starts_at' => now()->addDay()]);

        $this->actingAs($this->student)
            ->get('/')
            ->assertSeeInOrder(['Newest', 'Third', 'Second'])
            ->assertDontSee('Oldest live')
            ->assertDontSee('A Draft')
            ->assertDontSee('Has Ended')
            ->assertDontSee('Not Yet');
    }

    #[Test]
    public function no_announcements_says_so(): void
    {
        $this->actingAs($this->student)->get('/')->assertSee('No announcements right now');
    }

    #[Test]
    public function the_profile_summary_shows_the_students_details(): void
    {
        $this->actingAs($this->student)
            ->get('/')
            ->assertSee('Your details')
            ->assertSee('F/ND/24/0000077')
            ->assertSee('Computer Science');
    }

    #[Test]
    public function recent_activity_is_in_plain_words_and_safe(): void
    {
        $this->actingAs($this->student);
        $book = $this->book('<b>Bold</b> Notes');
        Audit::record('login');
        Audit::record('login');
        Audit::record('book_uploaded', $book);
        Audit::record('two_factor_enabled');
        Audit::record('some_internal_action');
        Audit::record('profile_updated', null, [], User::factory()->create()->id);

        $html = (string) $this->get('/')->getContent();

        $this->assertStringContainsString('You turned on two-step verification', $html);
        $this->assertStringContainsString('You shared &quot;&lt;b&gt;Bold&lt;/b&gt; Notes&quot;', $html);
        $this->assertSame(1, substr_count($html, 'You logged in'));
        $this->assertStringNotContainsString('some_internal_action', $html);
        $this->assertStringNotContainsString('You updated your profile', $html);
    }

    #[Test]
    public function reviewers_see_how_many_uploads_are_waiting(): void
    {
        $governor = User::factory()->for($this->department)->role(Role::Governor)->create(['level' => Level::ND1]);
        Book::factory()->count(2)->for($this->department)->uploadedBy($this->student)->create(['level' => Level::ND1]);
        // Another level's upload is another governor's to review.
        Book::factory()->for($this->department)->uploadedBy($this->student)->create(['level' => Level::HND2]);

        $this->actingAs($governor)->get('/')->assertSee('2 uploads waiting')->assertSee(route('review.index'));
        $this->actingAs($this->student)->get('/')->assertDontSee('uploads waiting');
    }
}
