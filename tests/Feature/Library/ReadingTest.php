<?php

namespace Tests\Feature\Library;

use App\Enums\BookStatus;
use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReadingTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->create();
    }

    private function read(User $user, Book $book, int $page, ?int $hoursAgo = 1, bool $finished = false): void
    {
        $user->readingProgress()->create([
            'book_id' => $book->id,
            'current_page' => $page,
            'progress_percent' => $finished ? 100 : 30,
            'completed_at' => $finished ? now() : null,
            'last_read_at' => now()->subHours((int) $hoursAgo),
        ]);
    }

    #[Test]
    public function guests_are_sent_to_log_in(): void
    {
        $this->get(route('reading.index'))->assertRedirect(route('login'));
    }

    #[Test]
    public function it_lists_only_the_students_books_most_recent_first(): void
    {
        $older = Book::factory()->approved()->create(['title' => 'Operating Systems', 'page_count' => 40]);
        $newer = Book::factory()->approved()->create(['title' => 'Data Structures', 'page_count' => 20]);
        $done = Book::factory()->approved()->create(['title' => 'Discrete Maths']);
        $removed = Book::factory()->status(BookStatus::Rejected)->create(['title' => 'Withdrawn Notes']);
        Book::factory()->approved()->create(['title' => 'Never Opened']);

        $this->read($this->student, $older, 12, 5);
        $this->read($this->student, $newer, 4, 1);
        $this->read($this->student, $done, 30, 2, finished: true);
        $this->read($this->student, $removed, 2);
        $this->read(User::factory()->create(), Book::factory()->approved()->create(['title' => 'Someone Else Book']), 1);

        $this->actingAs($this->student)->get(route('reading.index'))->assertOk()
            ->assertSee('3 books opened · 1 finished')
            ->assertSeeInOrder(['Data Structures', 'Discrete Maths', 'Operating Systems'])
            ->assertSee('Page 12 of 40')
            ->assertSee(route('books.read', $newer))
            ->assertDontSee('Withdrawn Notes')
            ->assertDontSee('Never Opened')
            ->assertDontSee('Someone Else Book');

        $this->get(route('reading.index', ['show' => 'reading']))
            ->assertSee('Operating Systems')->assertSee('Data Structures')->assertDontSee('Discrete Maths');

        $this->get(route('reading.index', ['show' => 'finished']))
            ->assertSee('Discrete Maths')->assertDontSee('Operating Systems');

        // An unknown tab shows everything.
        $this->get(route('reading.index', ['show' => 'nonsense']))->assertOk()->assertSee('Operating Systems')->assertSee('Discrete Maths');
    }

    #[Test]
    public function a_student_who_has_read_nothing_is_pointed_to_the_library(): void
    {
        $this->actingAs($this->student)->get(route('reading.index'))->assertOk()
            ->assertSee('No books opened yet')
            ->assertSee(route('library.index'));
    }

    #[Test]
    public function the_home_page_links_books_read_here(): void
    {
        $this->read($this->student, Book::factory()->approved()->create(), 3);

        $this->actingAs($this->student)->get(route('home'))->assertOk()
            ->assertSee('href="'.route('reading.index').'"', false);
    }
}
