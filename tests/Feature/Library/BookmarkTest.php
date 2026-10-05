<?php

namespace Tests\Feature\Library;

use App\Enums\BookStatus;
use App\Models\Book;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookmarkTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->department = Department::factory()->create();
        $this->student = User::factory()->for($this->department)->create();
    }

    private function approvedBook(string $title = 'A Saved Book'): Book
    {
        return Book::factory()->for($this->department)->approved()->create(['title' => $title]);
    }

    #[Test]
    public function a_book_can_be_saved_and_removed_without_leaving_the_page(): void
    {
        $book = $this->approvedBook();

        $this->actingAs($this->student)
            ->postJson(route('bookmarks.store', $book))
            ->assertOk()
            ->assertJson(['saved' => true]);
        $this->assertDatabaseHas('bookmarks', ['user_id' => $this->student->id, 'book_id' => $book->id]);

        $this->deleteJson(route('bookmarks.destroy', $book))
            ->assertOk()
            ->assertJson(['saved' => false]);
        $this->assertDatabaseMissing('bookmarks', ['user_id' => $this->student->id, 'book_id' => $book->id]);
    }

    #[Test]
    public function saving_twice_keeps_one_bookmark(): void
    {
        $book = $this->approvedBook();

        $this->actingAs($this->student)->postJson(route('bookmarks.store', $book));
        $this->postJson(route('bookmarks.store', $book))->assertOk();

        $this->assertSame(1, DB::table('bookmarks')->where('book_id', $book->id)->count());
    }

    #[Test]
    public function it_works_as_a_plain_form_too(): void
    {
        $book = $this->approvedBook();

        $this->actingAs($this->student)
            ->from(route('library.show', $book))
            ->post(route('bookmarks.store', $book))
            ->assertRedirect(route('library.show', $book))
            ->assertSessionHas('status', 'Saved to your books.');
    }

    #[Test]
    public function unapproved_books_cannot_be_saved(): void
    {
        $pending = Book::factory()->for($this->department)->status(BookStatus::Pending)->create();

        $this->actingAs($this->student)->postJson(route('bookmarks.store', $pending))->assertNotFound();

        $this->assertDatabaseCount('bookmarks', 0);
    }

    #[Test]
    public function the_saved_page_lists_my_approved_bookmarks_newest_first(): void
    {
        $older = $this->approvedBook('Older Save');
        $newer = $this->approvedBook('Newer Save');
        $withdrawn = $this->approvedBook('Withdrawn Later');
        $someoneElses = $this->approvedBook('Someone Else Saved This');

        DB::table('bookmarks')->insert([
            ['user_id' => $this->student->id, 'book_id' => $older->id, 'created_at' => now()->subDay()],
            ['user_id' => $this->student->id, 'book_id' => $newer->id, 'created_at' => now()],
            ['user_id' => $this->student->id, 'book_id' => $withdrawn->id, 'created_at' => now()],
            ['user_id' => User::factory()->create()->id, 'book_id' => $someoneElses->id, 'created_at' => now()],
        ]);
        $withdrawn->forceFill(['status' => BookStatus::Archived])->save();

        $this->actingAs($this->student)
            ->get('/saved')
            ->assertOk()
            ->assertSeeInOrder(['Newer Save', 'Older Save'])
            ->assertDontSee('Withdrawn Later')
            ->assertDontSee('Someone Else Saved This')
            ->assertSee('aria-pressed="true"', false);
    }

    #[Test]
    public function the_library_shows_which_books_are_saved(): void
    {
        $book = $this->approvedBook();
        $this->student->bookmarks()->attach($book->id);

        $this->actingAs($this->student)
            ->get('/library')
            ->assertSee('aria-pressed="true"', false);
    }

    #[Test]
    public function an_empty_saved_page_points_to_the_library(): void
    {
        $this->actingAs($this->student)
            ->get('/saved')
            ->assertOk()
            ->assertSee('Nothing saved yet')
            ->assertSee(route('library.index'), false);
    }
}
