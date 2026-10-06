<?php

namespace Tests\Feature\Uploads;

use App\Enums\TextStatus;
use App\Jobs\ImproveScanPage;
use App\Models\Book;
use App\Models\BookFile;
use App\Models\BookScanPage;
use App\Models\BookText;
use App\Models\User;
use App\Support\Uploads\PageReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImproveScanPageTest extends TestCase
{
    use RefreshDatabase;

    private BookFile $file;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        Queue::fake();
        config(['services.anthropic.key' => 'test-key']);

        $student = User::factory()->create();
        $this->actingAs($student)->post(route('uploads.store'), [
            'type' => 'scan',
            'title' => 'Networks',
            'author' => 'Tanenbaum',
            'level' => $student->level->value,
            'department_id' => $student->department_id,
            'pages' => [UploadedFile::fake()->image('1.jpg'), UploadedFile::fake()->image('2.jpg')],
            'page_text' => ['devlce text one', 'device text two'],
        ])->assertSessionHasNoErrors();

        $this->file = Book::firstOrFail()->currentFile()->firstOrFail();
    }

    /**
     * Stands in for Claude: returns the given text for every page.
     */
    private function fakeReader(?string $text): void
    {
        $this->mock(PageReader::class, fn (MockInterface $mock) => $mock->shouldReceive('read')->andReturn($text));
    }

    private function runJobs(): void
    {
        foreach ($this->file->scanPages()->pluck('id') as $id) {
            $this->app->call([new ImproveScanPage((int) $id), 'handle']);
        }
    }

    #[Test]
    public function the_book_text_is_replaced_once_every_page_is_read(): void
    {
        $this->fakeReader('AI text');
        $paths = $this->file->scanPages()->pluck('path')->all();

        $first = (int) $this->file->scanPages()->value('id');
        $this->app->call([new ImproveScanPage($first), 'handle']);

        // Half way: the status page shows progress, the device text stays.
        $this->getJson(route('uploads.status', Book::firstOrFail()))
            ->assertJson(['status' => 'queued', 'done' => 1, 'total' => 2]);
        $this->assertSame("devlce text one\n\ndevice text two", BookText::findOrFail($this->file->book_id)->text);

        $this->runJobs();

        $this->assertSame("AI text\n\nAI text", BookText::findOrFail($this->file->book_id)->text);
        $this->assertSame(TextStatus::Done, $this->file->fresh()?->text_status);
        $this->assertSame(0, BookScanPage::count());
        foreach ($paths as $path) {
            Storage::disk('private')->assertMissing((string) $path);
        }
    }

    #[Test]
    public function a_page_the_ai_cannot_read_keeps_the_device_text(): void
    {
        $this->fakeReader(null);

        $this->runJobs();

        $this->assertSame("devlce text one\n\ndevice text two", BookText::findOrFail($this->file->book_id)->text);
        $this->assertSame(TextStatus::Done, $this->file->fresh()?->text_status);
    }

    #[Test]
    public function a_job_out_of_retries_finishes_the_page_with_the_device_text(): void
    {
        $this->fakeReader('AI text');
        $ids = $this->file->scanPages()->pluck('id')->all();

        (new ImproveScanPage((int) $ids[0]))->failed(null);
        $this->app->call([new ImproveScanPage((int) $ids[1]), 'handle']);

        $this->assertSame("devlce text one\n\nAI text", BookText::findOrFail($this->file->book_id)->text);
    }
}
