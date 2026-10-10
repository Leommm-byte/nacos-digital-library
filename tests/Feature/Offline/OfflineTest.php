<?php

namespace Tests\Feature\Offline;

use App\Enums\BookStatus;
use App\Models\Book;
use App\Models\User;
use App\Support\Offline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OfflineTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        $this->student = User::factory()->create(['fullname' => 'Ngozi Okafor', 'matric_number' => 'F/ND/24/0001234']);
    }

    #[Test]
    public function the_service_worker_lists_the_build_and_never_caches_itself(): void
    {
        $response = $this->get('/sw.js')->assertOk();

        $this->assertStringStartsWith('application/javascript', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $script = (string) $response->getContent();
        $this->assertStringNotContainsString('__VERSION__', $script);
        $this->assertStringNotContainsString('__CORE__', $script);
        $this->assertMatchesRegularExpression('/const VERSION = "[0-9a-f]{12}";/', $script);
        $this->assertStringContainsString('"/offline"', $script);
        $this->assertStringContainsString('"/offline/read"', $script);
    }

    #[Test]
    public function the_offline_pages_hold_nothing_personal_even_when_signed_in(): void
    {
        $this->actingAs($this->student);

        foreach (['/offline', '/offline/read'] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertDontSee('Ngozi')
                ->assertDontSee('F/ND/24/0001234')
                ->assertDontSee('name="_token"', false)
                ->assertDontSee('data-user', false);
        }

        $this->get('/offline')->assertSee("You're offline", false);
    }

    #[Test]
    public function approved_books_with_a_file_can_be_kept_offline(): void
    {
        $book = Book::factory()->approved()->withPdf(2)->create();
        $pending = Book::factory()->status(BookStatus::Pending)->uploadedBy($this->student)->withPdf(2)->create();
        $file = $book->currentFile()->firstOrFail();

        $this->actingAs($this->student)->get(route('library.show', $book))
            ->assertOk()
            ->assertSee('data-keep-offline', false)
            ->assertSee(route('books.file', ['book' => $book, 'v' => substr($file->sha256, 0, 12)]), false)
            ->assertSee('data-owner="'.Offline::owner($this->student).'"', false);

        $this->get(route('library.show', $pending))->assertOk()->assertDontSee('data-keep-offline', false);
    }

    #[Test]
    public function the_owner_id_differs_per_student_and_reveals_nothing(): void
    {
        $other = User::factory()->create();
        $owner = Offline::owner($this->student);

        $this->assertNotSame($owner, Offline::owner($other));
        $this->assertSame($owner, Offline::owner($this->student));
        $this->assertStringNotContainsString((string) $this->student->id, $owner);

        $this->actingAs($this->student)->get(route('home'))->assertSee('data-user="'.$owner.'"', false);
    }

    #[Test]
    public function signed_in_pages_are_never_stored_by_the_browser(): void
    {
        $page = $this->actingAs($this->student)->get(route('home'))->assertOk();
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));

        // Logging out is instant: no Clear-Site-Data, which makes Chrome go
        // through its whole cache.
        $this->post(route('logout'))
            ->assertRedirect(route('login'))
            ->assertHeaderMissing('Clear-Site-Data');

        $this->assertStringNotContainsString('no-store', (string) $this->get(route('login'))->headers->get('Cache-Control'));
    }

    #[Test]
    public function pages_link_the_manifest_and_share_tags(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('rel="manifest"', false)
            ->assertSee('property="og:image" content="'.asset('images/share.png').'"', false)
            ->assertSee('name="twitter:card" content="summary_large_image"', false)
            ->assertSee('data-install', false);

        $this->assertFileExists(public_path('manifest.webmanifest'));
        $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true);
        $this->assertSame('standalone', $manifest['display']);
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
        }
    }
}
