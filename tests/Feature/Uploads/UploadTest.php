<?php

namespace Tests\Feature\Uploads;

use App\Enums\BookSource;
use App\Enums\BookStatus;
use App\Enums\Level;
use App\Enums\Role;
use App\Enums\TextStatus;
use App\Jobs\ImproveScanPage;
use App\Models\Book;
use App\Models\BookText;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\Support\DemoPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UploadTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        config(['services.anthropic.key' => null]);
        $this->department = Department::factory()->create(['is_active' => true]);
        $this->student = User::factory()->for($this->department)->create(['level' => Level::ND2]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function details(array $overrides = []): array
    {
        return [
            'title' => 'Operating Systems Past Questions',
            'author' => 'Dr. Adeyemi',
            'description' => 'COM 312, 2019 to 2024.',
            'level' => 'ND2',
            'department_id' => $this->department->id,
            ...$overrides,
        ];
    }

    private function pdf(int $pages = 3): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('os-past-questions.pdf', DemoPdf::make('OS', $pages));
    }

    /**
     * @return list<string>
     */
    private function storedFiles(): array
    {
        return Storage::disk('private')->allFiles();
    }

    #[Test]
    public function guests_are_sent_to_log_in(): void
    {
        $this->get(route('uploads.create'))->assertRedirect(route('login'));
        $this->post(route('uploads.store'))->assertRedirect(route('login'));
    }

    #[Test]
    public function the_form_shows_the_uploads_left_today(): void
    {
        $this->actingAs($this->student)
            ->get(route('uploads.create'))
            ->assertOk()
            ->assertSee('Share a book')
            ->assertSee('10 uploads left today');
    }

    #[Test]
    public function a_pdf_is_saved_for_review_with_its_text_and_cover(): void
    {
        $response = $this->actingAs($this->student)->post(route('uploads.store'), $this->details([
            'type' => 'pdf',
            'pdf' => $this->pdf(),
            'cover' => UploadedFile::fake()->image('cover.png', 600, 800),
            'page_count' => 3,
            'text' => "Question 1: explain   deadlock.\u{0007}\n\n\n\nQuestion 2: paging.",
        ]));

        $book = Book::firstOrFail();
        $response->assertRedirect(route('uploads.show', $book));

        $this->assertSame(BookStatus::Pending, $book->status);
        $this->assertSame($this->student->id, $book->uploader_id);
        $this->assertSame(3, $book->page_count);
        $this->assertSame("Question 1: explain deadlock.\n\nQuestion 2: paging.", BookText::findOrFail($book->id)->text);

        $file = $book->currentFile()->firstOrFail();
        $this->assertSame(BookSource::Pdf, $file->source);
        $this->assertSame(TextStatus::Device, $file->text_status);
        $this->assertSame('os-past-questions.pdf', $file->original_name);
        Storage::disk('private')->assertExists($file->path);

        // The cover was re-encoded as a JPEG.
        $this->assertNotNull($book->cover_path);
        $this->assertStringStartsWith("\xFF\xD8", (string) Storage::disk('private')->get($book->cover_path));

        $this->assertDatabaseHas('audit_logs', ['action' => 'book_uploaded', 'user_id' => $this->student->id]);
    }

    #[Test]
    public function the_upload_script_gets_json_back(): void
    {
        $this->actingAs($this->student)
            ->postJson(route('uploads.store'), $this->details(['type' => 'pdf', 'pdf' => $this->pdf()]))
            ->assertCreated()
            ->assertJson(['redirect' => route('uploads.show', Book::firstOrFail())]);

        $this->actingAs($this->student)
            ->postJson(route('uploads.store'), $this->details(['type' => 'pdf']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pdf');
    }

    #[Test]
    public function files_that_are_not_really_pdfs_are_refused(): void
    {
        $this->actingAs($this->student)
            ->post(route('uploads.store'), $this->details([
                'type' => 'pdf',
                'pdf' => UploadedFile::fake()->createWithContent('notes.pdf', '<script>alert(1)</script>'),
            ]))
            ->assertSessionHasErrors('pdf');

        $this->assertSame(0, Book::count());
        $this->assertSame([], $this->storedFiles());
    }

    #[Test]
    public function pdfs_over_the_size_limit_are_refused(): void
    {
        config(['uploads.pdf_max_kb' => 1]);

        $this->actingAs($this->student)
            ->post(route('uploads.store'), $this->details(['type' => 'pdf', 'pdf' => $this->pdf(20)]))
            ->assertSessionHasErrors(['pdf' => 'The PDF must be 1 KB or smaller.']);
    }

    #[Test]
    public function photos_of_pages_become_one_pdf_with_the_first_page_as_cover(): void
    {
        $this->actingAs($this->student)->post(route('uploads.store'), $this->details([
            'type' => 'scan',
            'pages' => [
                UploadedFile::fake()->image('p1.jpg', 3000, 4000),
                UploadedFile::fake()->image('p2.png', 800, 1100),
            ],
            'page_text' => ['Linked lists', 'Binary trees'],
        ]))->assertSessionHasNoErrors();

        $book = Book::firstOrFail();
        $file = $book->currentFile()->firstOrFail();
        $pdf = (string) Storage::disk('private')->get($file->path);

        $this->assertSame(BookSource::Scan, $file->source);
        $this->assertSame(2, $book->page_count);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(2, substr_count($pdf, '/Type /Page '));
        // Pages were shrunk to at most 2000 pixels.
        $this->assertStringContainsString('/Width 1500 /Height 2000', $pdf);

        $this->assertNotNull($book->cover_path);
        $this->assertSame("Linked lists\n\nBinary trees", BookText::findOrFail($book->id)->text);
        $this->assertSame(TextStatus::Device, $file->text_status);
        // Without AI, no page images are kept.
        $this->assertEqualsCanonicalizing([$file->path, $book->cover_path], $this->storedFiles());
    }

    #[Test]
    public function with_an_api_key_each_scanned_page_is_queued_for_ai(): void
    {
        Queue::fake();
        config(['services.anthropic.key' => 'test-key']);

        $this->actingAs($this->student)->post(route('uploads.store'), $this->details([
            'type' => 'scan',
            'pages' => [UploadedFile::fake()->image('p1.jpg'), UploadedFile::fake()->image('p2.jpg'), UploadedFile::fake()->image('p3.jpg')],
        ]))->assertSessionHasNoErrors();

        $file = Book::firstOrFail()->currentFile()->firstOrFail();

        $this->assertSame(TextStatus::Queued, $file->text_status);
        $this->assertSame(3, $file->scanPages()->count());
        Queue::assertPushed(ImproveScanPage::class, 3);
        Storage::disk('private')->assertExists($file->scanPages()->firstOrFail()->path ?? '');
    }

    #[Test]
    public function an_unreadable_image_leaves_nothing_behind(): void
    {
        // Looks like a JPEG to the type check, but isn't a valid image.
        $broken = UploadedFile::fake()->createWithContent('p2.jpg', "\xFF\xD8\xFF\xE0".str_repeat('x', 500));

        $this->actingAs($this->student)
            ->post(route('uploads.store'), $this->details([
                'type' => 'scan',
                'pages' => [UploadedFile::fake()->image('p1.jpg'), $broken],
            ]))
            ->assertSessionHasErrors('pages');

        $this->assertSame(0, Book::withTrashed()->count());
        $this->assertSame([], $this->storedFiles());
    }

    #[Test]
    public function pages_must_be_images_and_within_the_page_limit(): void
    {
        config(['uploads.max_pages' => 2]);

        $this->actingAs($this->student)
            ->post(route('uploads.store'), $this->details([
                'type' => 'scan',
                'pages' => [UploadedFile::fake()->image('1.jpg'), UploadedFile::fake()->image('2.jpg'), UploadedFile::fake()->image('3.jpg')],
            ]))
            ->assertSessionHasErrors('pages');

        $this->post(route('uploads.store'), $this->details([
            'type' => 'scan',
            'pages' => [UploadedFile::fake()->createWithContent('page.jpg', '%PDF-1.4 not an image')],
        ]))->assertSessionHasErrors('pages.0');

        $this->assertSame(0, Book::count());
    }

    #[Test]
    public function the_daily_and_pending_caps_apply_to_students_but_not_reviewers(): void
    {
        config(['uploads.per_day' => 2, 'uploads.pending' => 5]);
        Book::factory()->count(2)->for($this->department)->uploadedBy($this->student)->create();

        $this->actingAs($this->student)->get(route('uploads.create'))->assertSee("You have reached today's limit", false);
        $this->post(route('uploads.store'), $this->details(['type' => 'pdf', 'pdf' => $this->pdf()]))
            ->assertSessionHasErrors('upload');

        // Yesterday's uploads don't count towards today's, but still wait for review.
        $this->travel(25)->hours();
        config(['uploads.pending' => 2]);
        $this->post(route('uploads.store'), $this->details(['type' => 'pdf', 'pdf' => $this->pdf()]))
            ->assertSessionHasErrors(['upload' => 'You have 2 uploads waiting for review. You can upload more once some have been reviewed.']);

        $governor = User::factory()->for($this->department)->role(Role::Governor)->create();
        Book::factory()->count(3)->for($this->department)->uploadedBy($governor)->create();
        $this->actingAs($governor)
            ->post(route('uploads.store'), $this->details(['type' => 'pdf', 'pdf' => $this->pdf()]))
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function flagged_files_are_refused_when_virus_scanning_is_on(): void
    {
        $scanner = tempnam(sys_get_temp_dir(), 'clam');
        file_put_contents($scanner, "#!/bin/sh\nexit 1\n");
        chmod($scanner, 0755);
        config(['uploads.clamav' => $scanner]);

        $this->actingAs($this->student)
            ->post(route('uploads.store'), $this->details(['type' => 'pdf', 'pdf' => $this->pdf()]))
            ->assertSessionHasErrors(['upload' => 'This upload was flagged as unsafe and wasn\'t saved.']);

        $this->assertSame(0, Book::count());
        unlink($scanner);
    }

    #[Test]
    public function only_active_departments_can_be_chosen(): void
    {
        $closed = Department::factory()->create(['is_active' => false]);

        $this->actingAs($this->student)
            ->post(route('uploads.store'), $this->details(['type' => 'pdf', 'pdf' => $this->pdf(), 'department_id' => $closed->id]))
            ->assertSessionHasErrors('department_id');
    }
}
