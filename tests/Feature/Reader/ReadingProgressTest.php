<?php

namespace Tests\Feature\Reader;

use App\Enums\BookStatus;
use App\Models\Book;
use App\Models\Department;
use App\Models\ReadingProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReadingProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        $department = Department::factory()->create();
        $this->student = User::factory()->for($department)->create();
        $this->book = Book::factory()->for($department)->approved()->withPdf(20)->create();
    }

    private function progress(?User $user = null): ?ReadingProgress
    {
        return ReadingProgress::query()
            ->where('user_id', ($user ?? $this->student)->id)
            ->where('book_id', $this->book->id)
            ->first();
    }

    #[Test]
    public function the_page_and_percentage_are_saved(): void
    {
        $this->actingAs($this->student)
            ->post(route('books.progress', $this->book), ['page' => 5, 'pages' => 20])
            ->assertNoContent();

        $this->assertSame(5, $this->progress()?->current_page);
        $this->assertSame(25, $this->progress()?->progress_percent);
        $this->assertNull($this->progress()?->completed_at);
    }

    #[Test]
    public function going_back_keeps_the_page_but_never_lowers_the_percentage(): void
    {
        $this->actingAs($this->student)->post(route('books.progress', $this->book), ['page' => 10, 'pages' => 20]);
        $this->post(route('books.progress', $this->book), ['page' => 2, 'pages' => 20]);

        $this->assertSame(2, $this->progress()?->current_page);
        $this->assertSame(50, $this->progress()?->progress_percent);
    }

    #[Test]
    public function the_stored_page_count_wins_and_pages_beyond_the_end_are_clamped(): void
    {
        // The reader claims 25 pages; the file has 20.
        $this->actingAs($this->student)->post(route('books.progress', $this->book), ['page' => 25, 'pages' => 25]);

        $this->assertSame(20, $this->progress()?->current_page);
        $this->assertSame(100, $this->progress()?->progress_percent);
    }

    #[Test]
    public function the_book_is_marked_finished_once_at_95_percent(): void
    {
        $this->actingAs($this->student)->post(route('books.progress', $this->book), ['page' => 19, 'pages' => 20]);
        $finishedAt = $this->progress()?->completed_at;
        $this->assertNotNull($finishedAt);

        $this->travel(1)->day();
        $this->post(route('books.progress', $this->book), ['page' => 20, 'pages' => 20]);

        $this->assertTrue($finishedAt->equalTo($this->progress()?->completed_at));
    }

    #[Test]
    public function progress_belongs_to_the_signed_in_reader_only(): void
    {
        $other = User::factory()->create();

        $this->actingAs($this->student)->post(route('books.progress', $this->book), ['page' => 8, 'pages' => 20]);

        $this->assertNull($this->progress($other));
    }

    #[Test]
    public function bad_input_is_rejected(): void
    {
        $this->actingAs($this->student)
            ->postJson(route('books.progress', $this->book), ['page' => 0, 'pages' => 'many'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['page', 'pages']);

        $this->assertNull($this->progress());
    }

    #[Test]
    public function hidden_books_cannot_be_tracked(): void
    {
        $this->book->status = BookStatus::Pending;
        $this->book->save();

        $this->actingAs($this->student)
            ->post(route('books.progress', $this->book), ['page' => 3, 'pages' => 20])
            ->assertNotFound();

        $this->assertNull($this->progress());
    }

    #[Test]
    public function guests_cannot_save_progress(): void
    {
        $this->post(route('books.progress', $this->book), ['page' => 3, 'pages' => 20])
            ->assertRedirect(route('login'));
    }
}
