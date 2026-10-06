<?php

namespace Tests\Feature\Uploads;

use App\Enums\BookStatus;
use App\Enums\Role;
use App\Models\Book;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UploadPagesTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        $this->department = Department::factory()->create();
        $this->student = User::factory()->for($this->department)->create();
    }

    #[Test]
    public function students_see_only_their_own_uploads_with_their_status(): void
    {
        Book::factory()->for($this->department)->uploadedBy($this->student)->create(['title' => 'My Pending Notes']);
        Book::factory()->for($this->department)->uploadedBy($this->student)->approved()->create(['title' => 'My Approved Book']);
        Book::factory()->for($this->department)->uploadedBy(User::factory()->create())->create(['title' => 'Someone Else']);

        $this->actingAs($this->student)
            ->get(route('uploads.index'))
            ->assertOk()
            ->assertSeeInOrder(['My Approved Book', 'Approved'])
            ->assertSee('My Pending Notes')
            ->assertSee('Pending review')
            ->assertDontSee('Someone Else');
    }

    #[Test]
    public function an_empty_list_invites_a_first_upload(): void
    {
        $this->actingAs($this->student)->get(route('uploads.index'))->assertSee('Nothing uploaded yet');
    }

    #[Test]
    public function the_status_page_thanks_the_uploader_right_after_uploading(): void
    {
        $book = Book::factory()->for($this->department)->uploadedBy($this->student)->withPdf(2)->create(['title' => 'Compilers']);

        $this->actingAs($this->student)
            ->withSession(['uploaded' => true])
            ->get(route('uploads.show', $book))
            ->assertOk()
            ->assertSee('Thanks for sharing!')
            ->assertSee('is waiting for review', false)
            ->assertSee('Upload another');

        // The thanks only shows once (it's flashed after uploading).
        $this->flushSession();
        $this->actingAs($this->student)->get(route('uploads.show', $book))->assertDontSee('Thanks for sharing!');
    }

    #[Test]
    public function other_students_cannot_see_an_upload_but_reviewers_can(): void
    {
        $book = Book::factory()->for($this->department)->uploadedBy($this->student)->create();

        $this->actingAs(User::factory()->create())->get(route('uploads.show', $book))->assertNotFound();
        $this->getJson(route('uploads.status', $book))->assertNotFound();

        $governor = User::factory()->role(Role::Governor)->create();
        $this->actingAs($governor)->get(route('uploads.show', $book))->assertOk();
    }

    #[Test]
    public function an_approved_upload_links_to_the_library(): void
    {
        $book = Book::factory()->for($this->department)->uploadedBy($this->student)->approved()->withPdf()->create();
        $this->assertSame(BookStatus::Approved, $book->status);

        $this->actingAs($this->student)
            ->get(route('uploads.show', $book))
            ->assertSee('See it in the library')
            ->assertSee(route('library.show', $book));
    }

    #[Test]
    public function the_account_menu_and_library_link_to_uploads(): void
    {
        $this->actingAs($this->student)
            ->get(route('library.index'))
            ->assertSee(route('uploads.create'))
            ->assertSee('Your uploads');
    }
}
