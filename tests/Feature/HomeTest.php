<?php

namespace Tests\Feature;

use App\Enums\Level;
use App\Models\Book;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HomeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guests_see_the_landing_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Create your account')
            ->assertSee(route('login'), false);
    }

    #[Test]
    public function students_see_a_greeting_and_books_for_their_level(): void
    {
        $department = Department::factory()->create(['name' => 'Computer Science']);
        $user = User::factory()->for($department)->create(['fullname' => 'Ada Obi', 'level' => Level::ND2]);
        Book::factory()->for($department)->approved()->create(['title' => 'For My Level', 'level' => Level::ND2]);
        Book::factory()->for($department)->approved()->create(['title' => 'Another Level', 'level' => Level::HND1]);

        $this->travelTo(now()->timezone('Africa/Lagos')->setTime(9, 0));

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertSee('Good morning')
            ->assertSee('Ada')
            ->assertSee('Recommended for you')
            ->assertSee('New for ND2, from Computer Science first.')
            ->assertSee('For My Level')
            ->assertDontSee('Another Level')
            ->assertSee('Nothing saved yet');
    }

    #[Test]
    public function saved_books_appear_on_the_home_page(): void
    {
        $department = Department::factory()->create();
        $user = User::factory()->for($department)->create(['level' => Level::ND1]);
        $book = Book::factory()->for($department)->approved()->create(['title' => 'My Saved Book', 'level' => Level::HND2]);
        $user->bookmarks()->attach($book->id);

        $this->actingAs($user)
            ->get('/')
            ->assertSee('My Saved Book')
            ->assertSee('1 saved');
    }
}
