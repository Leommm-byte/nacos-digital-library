<?php

namespace Tests\Feature\Review;

use App\Enums\BookStatus;
use App\Enums\Level;
use App\Enums\ReviewAction;
use App\Enums\Role;
use App\Models\Book;
use App\Models\Department;
use App\Models\User;
use App\Notifications\UploadReviewed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private User $governor;

    private User $uploader;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        $this->department = Department::factory()->create();
        $this->governor = User::factory()->for($this->department)->role(Role::Governor)->create(['level' => Level::ND1]);
        $this->uploader = User::factory()->for($this->department)->create(['fullname' => 'Ada Uploader']);
    }

    private function pending(string $title = 'Algorithms Notes'): Book
    {
        return Book::factory()->for($this->department)->uploadedBy($this->uploader)->withPdf(3)->create(['title' => $title, 'level' => Level::ND1]);
    }

    #[Test]
    public function only_reviewers_can_open_the_queue(): void
    {
        $this->actingAs($this->uploader)->get(route('review.index'))->assertForbidden();
        $this->actingAs(User::factory()->role(Role::CourseRep)->create())->get(route('review.index'))->assertForbidden();
        $this->actingAs($this->governor)->get(route('review.index'))->assertOk();
    }

    #[Test]
    public function the_queue_lists_waiting_uploads_oldest_first_with_counts(): void
    {
        $this->pending('Older Upload');
        $this->travel(1)->hour();
        $this->pending('Newer Upload');
        Book::factory()->for($this->department)->approved()->create(['title' => 'Already In Library', 'level' => Level::ND1]);

        $this->actingAs($this->governor)
            ->get(route('review.index'))
            ->assertSeeInOrder(['Older Upload', 'Newer Upload'])
            ->assertSee('Ada Uploader')
            ->assertDontSee('Already In Library')
            ->assertSee('2 uploads waiting');

        $this->get(route('review.index', ['status' => 'approved']))->assertSee('Already In Library')->assertDontSee('Older Upload');
        $this->get(route('review.index', ['status' => 'all', 'q' => 'newer']))->assertSee('Newer Upload')->assertDontSee('Older Upload');
    }

    #[Test]
    public function an_empty_queue_says_so(): void
    {
        $this->actingAs($this->governor)->get(route('review.index'))->assertSee('All caught up');
    }

    #[Test]
    public function approving_puts_the_book_in_the_library_and_tells_the_uploader(): void
    {
        Notification::fake();
        $book = $this->pending();

        $this->actingAs($this->governor)
            ->post(route('review.decide', $book), ['action' => 'approved'])
            ->assertRedirect(route('review.index'))
            ->assertSessionHas('status', 'Approved "Algorithms Notes".');

        $book->refresh();
        $this->assertSame(BookStatus::Approved, $book->status);
        $this->assertSame($this->governor->id, $book->reviewed_by);
        $this->assertNotNull($book->approved_at);
        $this->assertDatabaseHas('book_reviews', ['book_id' => $book->id, 'reviewer_id' => $this->governor->id, 'action' => 'approved']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'book_approved', 'user_id' => $this->governor->id]);

        Notification::assertSentTo($this->uploader, UploadReviewed::class, fn (UploadReviewed $n) => $n->action === ReviewAction::Approved);

        $this->actingAs($this->uploader)->get(route('library.index'))->assertSee('Algorithms Notes');
    }

    #[Test]
    public function returning_or_rejecting_needs_a_note_for_the_uploader(): void
    {
        $book = $this->pending();

        $this->actingAs($this->governor)
            ->post(route('review.decide', $book), ['action' => 'rejected'])
            ->assertSessionHasErrors('comment');
        $this->assertSame(BookStatus::Pending, Book::findOrFail($book->id)->status);

        $this->post(route('review.decide', $book), ['action' => 'changes_requested', 'comment' => 'Page 3 is blurry, please retake it.'])
            ->assertSessionHasNoErrors();
        $this->assertSame(BookStatus::ChangesRequested, Book::findOrFail($book->id)->status);
    }

    #[Test]
    public function the_uploader_sees_the_decision_in_the_app(): void
    {
        $book = $this->pending();

        $this->actingAs($this->governor)->post(route('review.decide', $book), ['action' => 'changes_requested', 'comment' => 'Add the course code.']);

        $notification = $this->uploader->notifications()->firstOrFail();
        $this->assertSame('"Algorithms Notes" needs a few changes', $notification->data['headline']);

        $this->actingAs($this->uploader)
            ->get(route('uploads.show', $book))
            ->assertSee('A reviewer asked for changes')
            ->assertSee('Add the course code.')
            ->assertSee('Make changes');
    }

    #[Test]
    public function uploaders_with_a_verified_email_are_also_emailed(): void
    {
        $book = $this->pending();
        $notification = new UploadReviewed($book, ReviewAction::Approved, null);

        // No email, or one not yet verified: in the app only.
        $this->uploader->forceFill(['email' => null, 'email_verified_at' => null])->save();
        $this->assertSame(['database'], $notification->via($this->uploader));
        $this->uploader->forceFill(['email' => 'ada@example.test'])->save();
        $this->assertSame(['database'], $notification->via($this->uploader));

        $this->uploader->forceFill(['email' => 'ada@example.test', 'email_verified_at' => now()])->save();
        $this->assertSame(['database', 'mail'], $notification->via($this->uploader));
        $this->assertSame('"Algorithms Notes" is now in the library', $notification->toMail($this->uploader)->subject);
    }

    #[Test]
    public function a_book_can_only_be_decided_once(): void
    {
        $book = $this->pending();
        $other = User::factory()->role(Role::Governor)->create(['level' => Level::ND1]);

        $this->actingAs($this->governor)->post(route('review.decide', $book), ['action' => 'approved']);

        // The second reviewer's page was opened before the first decided.
        $this->actingAs($other)
            ->post(route('review.decide', $book), ['action' => 'rejected', 'comment' => 'No.'])
            ->assertSessionHasErrors(['action' => 'Someone else has already reviewed this upload.']);

        $this->assertSame(BookStatus::Approved, Book::findOrFail($book->id)->status);
        $this->assertSame(1, $book->reviews()->count());
    }

    #[Test]
    public function nobody_decides_their_own_upload(): void
    {
        $own = Book::factory()->for($this->department)->uploadedBy($this->governor)->withPdf()->create(['level' => Level::ND1]);

        $this->actingAs($this->governor)
            ->get(route('review.show', $own))
            ->assertOk()
            ->assertSee('another reviewer has to decide it');

        $this->post(route('review.decide', $own), ['action' => 'approved'])->assertForbidden();
    }

    #[Test]
    public function the_review_page_shows_details_preview_and_history(): void
    {
        $book = $this->pending();

        $this->actingAs($this->governor)
            ->get(route('review.show', $book))
            ->assertOk()
            ->assertSee('Ada Uploader')
            ->assertSee('data-pdf-preview', false)
            ->assertSee('Confirm decision')
            ->assertSee('Uploaded');
    }

    #[Test]
    public function after_a_decision_the_next_waiting_upload_opens(): void
    {
        $first = $this->pending('First');
        $this->travel(1)->minute();
        $second = $this->pending('Second');

        $this->actingAs($this->governor)
            ->post(route('review.decide', $first), ['action' => 'approved'])
            ->assertRedirect(route('review.show', $second));
    }

    #[Test]
    public function reviewers_see_how_many_uploads_are_waiting_in_the_menu(): void
    {
        $this->pending();
        $this->pending('Another');

        $this->actingAs($this->governor)->get(route('home'))->assertSee('Review uploads')->assertSee('menu-count', false);
        $this->actingAs($this->uploader)->get(route('home'))->assertDontSee('Review uploads');
    }

    #[Test]
    public function governors_only_see_their_own_levels_uploads(): void
    {
        $mine = $this->pending('ND1 Notes');
        $other = Book::factory()->for($this->department)->uploadedBy($this->uploader)->withPdf()->create(['title' => 'HND2 Notes', 'level' => Level::HND2]);

        $this->actingAs($this->governor)->get(route('review.index'))->assertOk()
            ->assertSee('ND1 uploads')
            ->assertSee('ND1 Notes')->assertDontSee('HND2 Notes')
            ->assertDontSee('All levels');
        $this->get(route('review.show', $other))->assertNotFound();
        $this->post(route('review.decide', $other), ['action' => ReviewAction::Approved->value])->assertForbidden();
        $this->get(route('review.show', $mine))->assertOk();

        // Admins see every level.
        $this->actingAs(User::factory()->role(Role::Admin)->create())->get(route('review.index'))
            ->assertSee('ND1 Notes')->assertSee('HND2 Notes')->assertSee('All levels');
    }
}
