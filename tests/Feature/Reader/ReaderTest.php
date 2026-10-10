<?php

namespace Tests\Feature\Reader;

use App\Enums\BookStatus;
use App\Enums\Role;
use App\Models\Book;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReaderTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        $this->department = Department::factory()->create();
        $this->student = User::factory()->for($this->department)->create(['matric_number' => 'F/ND/24/0000099']);
    }

    #[Test]
    public function guests_are_sent_to_log_in(): void
    {
        $book = Book::factory()->for($this->department)->approved()->withPdf()->create();

        $this->get(route('books.read', $book))->assertRedirect(route('login'));
        $this->get(route('books.file', $book))->assertRedirect(route('login'));
    }

    #[Test]
    public function the_reader_opens_with_the_file_url_and_a_watermark(): void
    {
        $book = Book::factory()->for($this->department)->approved()->withPdf(5)->create(['title' => 'Compilers']);

        $this->actingAs($this->student)
            ->get(route('books.read', $book))
            ->assertOk()
            ->assertSee('Compilers')
            ->assertSee('data-reader', false)
            ->assertSee('data-src="'.e(route('books.file', ['book' => $book, 'v' => substr($book->currentFile->sha256, 0, 12)])).'"', false)
            ->assertSee('data-start-page="1"', false)
            ->assertSee('data-watermark="F/ND/24/0000099"', false);
    }

    #[Test]
    public function the_reader_reopens_at_the_saved_page(): void
    {
        $book = Book::factory()->for($this->department)->approved()->withPdf(10)->create();

        $this->actingAs($this->student)->post(route('books.progress', $book), ['page' => 7, 'pages' => 10]);

        $this->get(route('books.read', $book))->assertSee('data-start-page="7"', false);
        $this->get(route('library.show', $book))->assertSee('Continue reading')->assertSee('page 7');
    }

    #[Test]
    public function a_read_is_counted_once_per_session_and_added_to_history(): void
    {
        $book = Book::factory()->for($this->department)->approved()->withPdf()->create();

        $this->actingAs($this->student)->get(route('books.read', $book))->assertOk();
        $this->get(route('books.read', $book))->assertOk();

        $this->assertSame(1, $book->fresh()->views_count);
        $this->assertDatabaseHas('reading_progress', ['user_id' => $this->student->id, 'book_id' => $book->id, 'current_page' => 1]);
    }

    #[Test]
    public function books_waiting_for_review_are_hidden_and_leave_no_trace(): void
    {
        $uploader = User::factory()->for($this->department)->create();
        $book = Book::factory()->for($this->department)->uploadedBy($uploader)
            ->status(BookStatus::Pending)->withPdf()->create();

        $this->actingAs($this->student)->get(route('books.read', $book))->assertNotFound();
        $this->get(route('books.file', $book))->assertNotFound();

        $this->assertSame(0, $book->fresh()->views_count);
        $this->assertDatabaseMissing('reading_progress', ['book_id' => $book->id]);
    }

    #[Test]
    public function uploaders_and_reviewers_can_read_a_book_waiting_for_review_without_counting_it(): void
    {
        $uploader = User::factory()->for($this->department)->create();
        $book = Book::factory()->for($this->department)->uploadedBy($uploader)
            ->status(BookStatus::Pending)->withPdf()->create();
        $governor = User::factory()->for($this->department)->role(Role::Governor)->create(['level' => $book->level]);

        $this->actingAs($uploader)->get(route('books.read', $book))->assertOk()->assertSee('Pending review');
        $this->actingAs($governor)->get(route('books.read', $book))->assertOk();
        $this->get(route('books.file', $book))->assertOk();

        $this->assertSame(0, $book->fresh()->views_count);
        $this->assertDatabaseMissing('reading_progress', ['book_id' => $book->id]);
    }

    #[Test]
    public function a_book_without_a_file_says_so(): void
    {
        $book = Book::factory()->for($this->department)->approved()->create();

        $this->actingAs($this->student)
            ->get(route('books.read', $book))
            ->assertOk()
            ->assertSee("This book isn't ready to read yet", false)
            ->assertDontSee('data-reader ', false);

        $this->get(route('library.show', $book))->assertSee('Not available yet');
        $this->get(route('books.file', $book))->assertNotFound();
    }
}
