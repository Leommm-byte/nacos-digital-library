<?php

namespace Tests\Feature\Library;

use App\Enums\BookStatus;
use App\Enums\Level;
use App\Enums\Role;
use App\Models\Book;
use App\Models\Department;
use App\Models\User;
use App\Support\Catalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LibraryTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->department = Department::factory()->create(['name' => 'Computer Science']);
        $this->student = User::factory()->for($this->department)->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function book(array $attributes = [], ?BookStatus $status = BookStatus::Approved): Book
    {
        $factory = Book::factory()->for($this->department);

        $factory = $status === BookStatus::Approved ? $factory->approved() : $factory->status($status ?? BookStatus::Pending);

        return $factory->create($attributes);
    }

    #[Test]
    public function guests_must_log_in(): void
    {
        $this->get('/library')->assertRedirect(route('login'));
        $this->get('/saved')->assertRedirect(route('login'));
    }

    #[Test]
    public function the_catalog_lists_only_approved_books_in_open_departments(): void
    {
        $this->book(['title' => 'Approved Book']);
        $this->book(['title' => 'Pending Book'], BookStatus::Pending);
        $this->book(['title' => 'Rejected Book'], BookStatus::Rejected);
        Book::factory()->for(Department::factory()->create(['is_active' => false]))->approved()->create(['title' => 'Closed Department Book']);

        $this->actingAs($this->student)
            ->get('/library')
            ->assertOk()
            ->assertSee('Approved Book')
            ->assertDontSee('Pending Book')
            ->assertDontSee('Rejected Book')
            ->assertDontSee('Closed Department Book');
    }

    #[Test]
    public function books_can_be_filtered_by_level(): void
    {
        $this->book(['title' => 'First Year Maths', 'level' => Level::ND1]);
        $this->book(['title' => 'Final Year Project Guide', 'level' => Level::HND2]);

        $this->actingAs($this->student)
            ->get('/library?level=HND2')
            ->assertSee('Final Year Project Guide')
            ->assertDontSee('First Year Maths');
    }

    #[Test]
    public function books_can_be_sorted_by_title(): void
    {
        $this->book(['title' => 'Zoology Notes']);
        $this->book(['title' => 'Algebra Basics']);

        $this->actingAs($this->student)
            ->get('/library?sort=title')
            ->assertSeeInOrder(['Algebra Basics', 'Zoology Notes']);
    }

    #[Test]
    public function the_catalog_is_paged(): void
    {
        Book::factory()->count(Catalog::PER_PAGE + 3)->for($this->department)->approved()->create();

        $this->actingAs($this->student);

        $first = $this->get('/library')->assertOk()->viewData('books');
        $second = $this->get('/library?page=2')->assertOk()->viewData('books');

        $this->assertCount(Catalog::PER_PAGE, $first);
        $this->assertCount(3, $second);
        $this->assertSame(Catalog::PER_PAGE + 3, $first->total());
    }

    #[Test]
    public function an_empty_search_says_so(): void
    {
        $this->book(['title' => 'Operating Systems']);

        $this->actingAs($this->student)
            ->get('/library?q=quantum+chromodynamics')
            ->assertOk()
            ->assertSee('No books found')
            ->assertDontSee('Operating Systems');
    }

    #[Test]
    public function a_book_has_a_detail_page_with_related_books(): void
    {
        $book = $this->book(['title' => 'Computer Networks', 'author' => 'A. Tanenbaum', 'level' => Level::ND2, 'description' => 'Layers and protocols.']);
        $this->book(['title' => 'Network Security', 'level' => Level::ND2]);
        $this->book(['title' => 'Unrelated Level', 'level' => Level::HND1]);

        $this->actingAs($this->student)
            ->get(route('library.show', $book))
            ->assertOk()
            ->assertSee('Computer Networks')
            ->assertSee('A. Tanenbaum')
            ->assertSee('Layers and protocols.')
            ->assertSee('Network Security')
            ->assertDontSee('Unrelated Level');
    }

    #[Test]
    public function unapproved_books_are_hidden_except_from_their_uploader_and_reviewers(): void
    {
        $uploader = User::factory()->for($this->department)->create();
        $pending = $this->book(['uploader_id' => $uploader->id], BookStatus::Pending);
        // Reviewers: the governor of the book's level, not another level's.
        $governor = User::factory()->role(Role::Governor)->create(['level' => $pending->level]);
        $otherLevel = User::factory()->role(Role::Governor)->create(['level' => $pending->level === Level::ND1 ? Level::ND2 : Level::ND1]);

        $this->actingAs($this->student)->get(route('library.show', $pending))->assertNotFound();
        $this->actingAs($uploader)->get(route('library.show', $pending))->assertOk()->assertSee('only visible to you and reviewers');
        $this->actingAs($governor)->get(route('library.show', $pending))->assertOk();
        $this->actingAs($otherLevel)->get(route('library.show', $pending))->assertNotFound();
    }

    #[Test]
    public function book_urls_use_the_public_id(): void
    {
        $book = $this->book();

        $this->assertStringContainsString($book->public_id, route('library.show', $book));
        $this->actingAs($this->student)->get('/library/'.$book->id)->assertNotFound();
    }

    #[Test]
    public function covers_are_served_privately_with_long_caching(): void
    {
        Storage::fake('private');
        Storage::disk('private')->putFileAs('covers', UploadedFile::fake()->image('cover.jpg', 300, 400), 'one.jpg');
        $book = $this->book(['cover_path' => 'covers/one.jpg']);

        $url = $book->coverUrl();
        $this->assertNotNull($url);
        $this->assertStringContainsString('v=', $url);

        $response = $this->actingAs($this->student)->get($url)->assertOk();
        $this->assertStringContainsString('max-age=31536000', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));

        $this->get(route('library.index'))->assertSee('loading="lazy"', false);
    }

    #[Test]
    public function covers_of_hidden_books_and_missing_covers_are_not_found(): void
    {
        Storage::fake('private');
        Storage::disk('private')->putFileAs('covers', UploadedFile::fake()->image('cover.jpg'), 'two.jpg');
        $pending = $this->book(['cover_path' => 'covers/two.jpg'], BookStatus::Pending);
        $noCover = $this->book();

        $this->actingAs($this->student)->get(route('library.cover', $pending))->assertNotFound();
        $this->get(route('library.cover', $noCover))->assertNotFound();
    }

    #[Test]
    public function books_without_a_cover_get_a_placeholder(): void
    {
        $this->book(['title' => 'Placeholder Book']);

        $this->actingAs($this->student)
            ->get('/library')
            ->assertSee('book-cover-placeholder', false);
    }

    #[Test]
    public function the_library_and_saved_books_are_in_the_navigation(): void
    {
        $this->actingAs($this->student)
            ->get('/')
            ->assertSee(route('library.index'), false)
            ->assertSee(route('bookmarks.index'), false);
    }
}
