<?php

namespace Tests\Feature;

use App\Enums\BookStatus;
use App\Enums\Role;
use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function user(Role $role): User
    {
        return User::factory()->role($role)->create();
    }

    #[Test]
    public function the_role_middleware_lets_in_that_role_and_above(): void
    {
        Route::middleware(['web', 'auth', 'role:governor'])->get('/_test/governors', fn () => 'ok');

        $this->get('/_test/governors')->assertRedirect(route('login'));
        $this->actingAs($this->user(Role::Student))->get('/_test/governors')->assertForbidden();
        $this->actingAs($this->user(Role::CourseRep))->get('/_test/governors')->assertForbidden();
        $this->actingAs($this->user(Role::Governor))->get('/_test/governors')->assertOk();
        $this->actingAs($this->user(Role::Admin))->get('/_test/governors')->assertOk();
    }

    #[Test]
    public function only_admins_reach_the_admin_area(): void
    {
        $this->assertTrue(Gate::forUser($this->user(Role::Admin))->allows('access-admin'));
        $this->assertFalse(Gate::forUser($this->user(Role::Governor))->allows('access-admin'));
    }

    #[Test]
    public function governors_and_admins_review_uploads(): void
    {
        $this->assertTrue(Gate::forUser($this->user(Role::Governor))->allows('review-uploads'));
        $this->assertTrue(Gate::forUser($this->user(Role::Admin))->allows('review-uploads'));
        $this->assertFalse(Gate::forUser($this->user(Role::CourseRep))->allows('review-uploads'));
    }

    #[Test]
    public function unapproved_books_are_visible_only_to_their_uploader_and_reviewers(): void
    {
        $uploader = $this->user(Role::Student);
        $pending = Book::factory()->uploadedBy($uploader)->create();
        $approved = Book::factory()->approved()->create();

        $this->assertTrue($uploader->can('view', $pending));
        $this->assertFalse($this->user(Role::Student)->can('view', $pending));
        $this->assertFalse($this->user(Role::CourseRep)->can('view', $pending));
        $this->assertTrue($this->user(Role::Governor)->can('view', $pending));
        $this->assertTrue($this->user(Role::Student)->can('view', $approved));
    }

    #[Test]
    public function uploaders_edit_their_book_until_it_is_approved(): void
    {
        $uploader = $this->user(Role::Student);
        $pending = Book::factory()->uploadedBy($uploader)->create();
        $changes = Book::factory()->uploadedBy($uploader)->status(BookStatus::ChangesRequested)->create();
        $approved = Book::factory()->uploadedBy($uploader)->approved()->create();

        $this->assertTrue($uploader->can('update', $pending));
        $this->assertTrue($uploader->can('delete', $changes));
        $this->assertFalse($uploader->can('update', $approved));
        $this->assertFalse($this->user(Role::Governor)->can('update', $pending));
        $this->assertTrue($this->user(Role::Admin)->can('update', $approved));
    }

    #[Test]
    public function nobody_reviews_their_own_upload(): void
    {
        $governor = $this->user(Role::Governor);
        $own = Book::factory()->uploadedBy($governor)->create();
        $other = Book::factory()->create();

        $this->assertFalse($governor->can('review', $own));
        $this->assertTrue($governor->can('review', $other));
        $this->assertFalse($this->user(Role::Student)->can('review', $other));
    }
}
