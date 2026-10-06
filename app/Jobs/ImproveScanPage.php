<?php

namespace App\Jobs;

use App\Enums\TextStatus;
use App\Models\BookScanPage;
use App\Support\Uploads\PageReader;
use App\Support\Uploads\UploadText;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Improves the searchable text of one scanned page with AI. Queued after a
 * scanned upload when an API key is set; the cron drains the queue every
 * minute. One page per job keeps each run short on shared hosting.
 *
 * When the last page is done, the pages' text replaces the book's search
 * text and the page images are deleted (they live on in the PDF).
 */
class ImproveScanPage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public int $scanPageId) {}

    public function handle(PageReader $reader): void
    {
        $page = BookScanPage::find($this->scanPageId);

        if ($page === null || $page->processed_at !== null) {
            return;
        }

        $disk = Storage::disk('private');
        $text = $page->path !== null && $disk->exists($page->path)
            ? $reader->read((string) $disk->get($page->path))
            : null;

        $this->finishPage($page, $text);
    }

    /**
     * Out of retries: keep the device text and move on.
     */
    public function failed(?Throwable $exception): void
    {
        $page = BookScanPage::find($this->scanPageId);

        if ($page !== null && $page->processed_at === null) {
            $this->finishPage($page, null);
        }
    }

    private function finishPage(BookScanPage $page, ?string $text): void
    {
        if ($page->path !== null) {
            Storage::disk('private')->delete($page->path);
        }

        $page->forceFill([
            'text' => $text !== null && $text !== '' ? UploadText::clean($text) : $page->text,
            'path' => null,
            'processed_at' => now(),
        ])->save();

        $file = $page->file()->with('book')->firstOrFail();

        if ($file->scanPages()->whereNull('processed_at')->exists()) {
            return;
        }

        $pages = $file->scanPages()->pluck('text')->filter()->implode("\n\n");
        $file->book->setSearchText(UploadText::clean($pages));
        $file->forceFill(['text_status' => TextStatus::Done])->save();
        BookScanPage::where('book_file_id', $file->id)->delete();
    }
}
