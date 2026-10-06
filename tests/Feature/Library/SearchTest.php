<?php

namespace Tests\Feature\Library;

use App\Models\Book;
use App\Models\Department;
use App\Models\User;
use App\Support\Catalog;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MySQL's full-text index only sees committed rows, so these tests can't run
 * inside RefreshDatabase's transaction. They commit their own data and
 * delete it again afterwards.
 */
class SearchTest extends TestCase
{
    private Department $department;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate');

        $this->department = Department::factory()->create();
        $this->student = User::factory()->for($this->department)->create();

        $books = [
            ['Data Structures and Algorithms', 'Robert Lafore', 'Arrays, linked lists and trees.'],
            ['Introduction to Databases', 'Chika Obi', 'Relational design and SQL.'],
            ['Organic Chemistry', 'Paula Bruice', 'Carbon compounds.'],
            ['C Programming', 'Dennis Ritchie', 'The classic.'],
        ];

        foreach ($books as [$title, $author, $description]) {
            Book::factory()->for($this->department)->approved()->create([
                'title' => $title,
                'author' => $author,
                'description' => $description,
            ]);
        }

        // Text read from the pages of an upload (PR 8).
        Book::where('title', 'C Programming')->firstOrFail()
            ->setSearchText("Chapter 3: pointers and memory.\n\nQuestion 4: explain recursion, then compare two sorting algorithms.");
    }

    protected function tearDown(): void
    {
        DB::table('books')->where('department_id', $this->department->id)->delete();
        DB::table('users')->where('id', $this->student->id)->delete();
        DB::table('departments')->where('id', $this->department->id)->delete();

        parent::tearDown();
    }

    /**
     * @return array<int, mixed>
     */
    private function search(string $q): array
    {
        return Catalog::query(['q' => $q])->pluck('title')->all();
    }

    #[Test]
    public function every_word_must_match_and_partial_words_count(): void
    {
        $this->assertSame(['Data Structures and Algorithms'], $this->search('data struct'));
        $this->assertSame(['Data Structures and Algorithms'], $this->search('algorithms structures'));
        $this->assertSame([], $this->search('data chemistry'));
    }

    #[Test]
    public function the_text_inside_a_book_is_searched(): void
    {
        $this->assertSame(['C Programming'], $this->search('pointers recursion'));
    }

    #[Test]
    public function a_title_match_ranks_above_a_match_in_the_text(): void
    {
        $this->assertSame(['Data Structures and Algorithms', 'C Programming'], $this->search('algorithms'));
    }

    #[Test]
    public function authors_and_descriptions_are_searched(): void
    {
        $this->assertSame(['Introduction to Databases'], $this->search('chika'));
        $this->assertSame(['Organic Chemistry'], $this->search('carbon compounds'));
    }

    #[Test]
    public function short_searches_still_find_titles(): void
    {
        $this->assertContains('C Programming', $this->search('C'));
    }

    #[Test]
    public function common_words_are_ignored(): void
    {
        $this->assertSame(['Introduction to Databases'], $this->search('the databases'));
    }

    #[Test]
    public function the_search_page_shows_matches(): void
    {
        $this->actingAs($this->student)
            ->get('/library?q=databases')
            ->assertOk()
            ->assertSee('Introduction to Databases')
            ->assertDontSee('Organic Chemistry');
    }
}
