<?php

namespace Tests\Feature;

use App\Enums\ReviewAction;
use App\Models\Book;
use App\Models\User;
use App\Notifications\UploadReviewed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->book = Book::factory()->uploadedBy($this->user)->create(['title' => 'Compilers']);
        $this->user->notify(new UploadReviewed($this->book, ReviewAction::Rejected, 'Duplicate of an existing book.'));
    }

    #[Test]
    public function the_bell_shows_how_many_are_unread(): void
    {
        $this->actingAs($this->user)
            ->get(route('home'))
            ->assertSee('Notifications (1 unread)')
            ->assertSee('count-badge', false);
    }

    #[Test]
    public function the_list_shows_the_decision_and_the_note(): void
    {
        $this->actingAs($this->user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Compilers')
            ->assertSee("wasn't approved")
            ->assertSee('Duplicate of an existing book.')
            ->assertSee('1 unread');
    }

    #[Test]
    public function opening_one_marks_it_read_and_goes_to_the_upload(): void
    {
        $notification = $this->user->notifications()->firstOrFail();

        $this->actingAs($this->user)
            ->get(route('notifications.open', $notification->id))
            ->assertRedirect(route('uploads.show', $this->book));

        $this->assertNotNull($notification->fresh()?->read_at);
    }

    #[Test]
    public function others_cant_open_someone_elses_notification(): void
    {
        $notification = $this->user->notifications()->firstOrFail();

        $this->actingAs(User::factory()->create())->get(route('notifications.open', $notification->id))->assertNotFound();
        $this->assertNull($notification->fresh()?->read_at);
    }

    #[Test]
    public function all_can_be_marked_read(): void
    {
        $this->actingAs($this->user)
            ->post(route('notifications.read-all'))
            ->assertRedirect(route('notifications.index'));

        $this->assertSame(0, $this->user->unreadNotifications()->count());
        $this->get(route('notifications.index'))->assertSee("You're all caught up.");
    }
}
