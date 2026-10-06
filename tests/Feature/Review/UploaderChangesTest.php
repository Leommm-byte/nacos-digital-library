<?php

namespace Tests\Feature\Review;

use App\Enums\BookStatus;
use App\Enums\ReviewAction;
use App\Enums\Role;
use App\Models\Book;
use App\Models\BookText;
use App\Models\Department;
use App\Models\ReadingProgress;
use App\Models\User;
use Database\Seeders\Support\DemoPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UploaderChangesTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private User $uploader;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        $this->department = Department::factory()->create();
        $this->uploader = User::factory()->for($this->department)->create();
    }

    private function returned(): Book
    {
        $book = Book::factory()->for($this->department)->uploadedBy($this->uploader)->withPdf(2)
            ->status(BookStatus::ChangesRequested)->create(['title' => 'Old Title']);
        $review = $book->reviews()->make(['action' => ReviewAction::ChangesRequested, 'comment' => 'Fix the title.']);
        $review->reviewer_id = User::factory()->role(Role::Governor)->create()->id;
        $review->save();

        return $book;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function details(Book $book, array $overrides = []): array
    {
        return [
            'title' => 'New Title',
            'author' => $book->author,
            'level' => $book->level->value,
            'department_id' => $book->department_id,
            ...$overrides,
        ];
    }

    #[Test]
    public function the_edit_page_shows_what_the_reviewer_asked_for(): void
    {
        $book = $this->returned();

        $this->actingAs($this->uploader)
            ->get(route('uploads.edit', $book))
            ->assertOk()
            ->assertSee('Fix the title.')
            ->assertSee('Send back for review');
    }

    #[Test]
    public function saving_changes_sends_the_upload_back_for_review(): void
    {
        $book = $this->returned();

        $this->actingAs($this->uploader)
            ->put(route('uploads.update', $book), $this->details($book))
            ->assertRedirect(route('uploads.show', $book))
            ->assertSessionHas('status', 'Thanks! Your changes were sent for review.');

        $book->refresh();
        $this->assertSame('New Title', $book->title);
        $this->assertSame(BookStatus::Pending, $book->status);
        $this->assertDatabaseHas('book_reviews', ['book_id' => $book->id, 'action' => 'resubmitted', 'reviewer_id' => $this->uploader->id]);
    }

    #[Test]
    public function a_new_pdf_and_cover_replace_the_old_ones(): void
    {
        $book = $this->returned();
        $book->setSearchText('old text');
        $oldSha = $book->currentFile()->firstOrFail()->sha256;

        $this->actingAs($this->uploader)->put(route('uploads.update', $book), $this->details($book, [
            'pdf' => UploadedFile::fake()->createWithContent('fixed.pdf', DemoPdf::make('Fixed', 5)),
            'cover' => UploadedFile::fake()->image('cover.jpg', 600, 800),
        ]))->assertSessionHasNoErrors();

        $book->refresh();
        $file = $book->currentFile()->firstOrFail();
        $this->assertNotSame($oldSha, $file->sha256);
        $this->assertSame('fixed.pdf', $file->original_name);
        $this->assertNull(BookText::find($book->id));
        $this->assertNotNull($book->cover_path);
        Storage::disk('private')->assertExists($book->cover_path);
    }

    #[Test]
    public function approved_books_cant_be_edited_by_their_uploader(): void
    {
        $book = Book::factory()->for($this->department)->uploadedBy($this->uploader)->approved()->create();

        $this->actingAs($this->uploader)->get(route('uploads.edit', $book))->assertNotFound();
        $this->put(route('uploads.update', $book), $this->details($book))->assertNotFound();
        $this->assertNotSame('New Title', $book->fresh()?->title);
    }

    #[Test]
    public function others_cant_edit_an_upload(): void
    {
        $book = $this->returned();

        $this->actingAs(User::factory()->create())->get(route('uploads.edit', $book))->assertNotFound();
    }

    #[Test]
    public function deleting_removes_the_files_bookmarks_and_history(): void
    {
        $book = $this->returned();
        $reader = User::factory()->create();
        DB::table('bookmarks')->insert(['user_id' => $reader->id, 'book_id' => $book->id]);
        ReadingProgress::upsert([['user_id' => $reader->id, 'book_id' => $book->id, 'last_read_at' => now()]], ['user_id', 'book_id'], ['last_read_at']);
        $book->setSearchText('some text');
        $paths = [$book->currentFile()->firstOrFail()->path];

        $this->actingAs($this->uploader)
            ->delete(route('uploads.destroy', $book))
            ->assertRedirect(route('uploads.index'));

        $this->assertSoftDeleted($book);
        $this->assertDatabaseMissing('bookmarks', ['book_id' => $book->id]);
        $this->assertDatabaseMissing('reading_progress', ['book_id' => $book->id]);
        $this->assertDatabaseMissing('book_files', ['book_id' => $book->id]);
        $this->assertNull(BookText::find($book->id));
        Storage::disk('private')->assertMissing($paths[0]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'book_deleted']);
    }

    #[Test]
    public function approved_books_are_deleted_by_admins_only(): void
    {
        $book = Book::factory()->for($this->department)->uploadedBy($this->uploader)->approved()->withPdf()->create();

        $this->actingAs($this->uploader)->delete(route('uploads.destroy', $book))->assertNotFound();
        $this->actingAs(User::factory()->role(Role::Governor)->create())->delete(route('uploads.destroy', $book))->assertNotFound();

        $this->actingAs(User::factory()->role(Role::Admin)->create())
            ->delete(route('uploads.destroy', $book))
            ->assertRedirect(route('review.index'));
        $this->assertSoftDeleted($book);
    }
}
