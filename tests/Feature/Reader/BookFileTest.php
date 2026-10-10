<?php

namespace Tests\Feature\Reader;

use App\Models\Book;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class BookFileTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        $department = Department::factory()->create();
        $this->student = User::factory()->for($department)->create();
        $this->book = Book::factory()->for($department)->approved()->withPdf(4)->create();
    }

    private function pdf(): string
    {
        return (string) Storage::disk('private')->get($this->book->currentFile->path);
    }

    /**
     * The bytes a file response sends (it streams, so it has no content).
     */
    private function body(Response $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    #[Test]
    public function the_whole_file_is_served_inline_and_advertises_ranges(): void
    {
        $response = $this->actingAs($this->student)->get(route('books.file', $this->book));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Length', (string) strlen($this->pdf()));
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame($this->pdf(), $this->body($response->baseResponse));
    }

    #[Test]
    public function a_range_request_gets_only_those_bytes(): void
    {
        $size = strlen($this->pdf());

        $response = $this->actingAs($this->student)
            ->withHeaders(['Range' => 'bytes=0-99'])
            ->get(route('books.file', $this->book));

        $response->assertStatus(206)
            ->assertHeader('Content-Range', "bytes 0-99/{$size}")
            ->assertHeader('Content-Length', '100');
        $this->assertSame(substr($this->pdf(), 0, 100), $this->body($response->baseResponse));
    }

    #[Test]
    public function the_browser_must_check_before_reusing_its_copy(): void
    {
        $version = substr($this->book->currentFile->sha256, 0, 12);
        $url = route('books.file', ['book' => $this->book, 'v' => $version]);

        $first = $this->actingAs($this->student)->get($url)->assertOk();
        $cacheControl = (string) $first->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringNotContainsString('max-age', $cacheControl);

        // Unchanged: an empty 304, the file isn't sent again.
        $again = $this->get($url, ['If-None-Match' => (string) $first->headers->get('ETag')]);
        $again->assertStatus(304);
        $this->assertSame('', $this->body($again->baseResponse));

        // After logging out the check fails, so a cached copy can't be used.
        $this->post(route('logout'));
        $this->get($url, ['If-None-Match' => (string) $first->headers->get('ETag')])->assertRedirect(route('login'));
    }

    #[Test]
    public function a_missing_file_is_not_found(): void
    {
        Storage::disk('private')->delete($this->book->currentFile->path);

        $this->actingAs($this->student)->get(route('books.file', $this->book))->assertNotFound();
    }
}
