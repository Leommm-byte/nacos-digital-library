<?php

namespace App\Support\Uploads;

use App\Enums\BookSource;
use App\Enums\BookStatus;
use App\Enums\Level;
use App\Enums\TextStatus;
use App\Jobs\ImproveScanPage;
use App\Models\Book;
use App\Models\BookScanPage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Stores an uploaded book: the PDF (as uploaded, or built from scanned
 * pages), its cover and its searchable text. Everything is written in one
 * transaction; if anything fails, the files written so far are deleted, so
 * nothing is left behind.
 */
class BookUpload
{
    /** Longest side of a stored cover, in pixels. */
    private const COVER_PIXELS = 900;

    /** @var list<string> */
    private array $written = [];

    /**
     * @param  array{title: string, author: string, description?: string|null, department_id: int, level: string, text?: string|null, page_count?: int|null, page_text?: array<int, string|null>}  $data
     * @param  list<UploadedFile>  $pages
     */
    public function store(User $user, array $data, ?UploadedFile $pdf, array $pages, ?UploadedFile $cover): Book
    {
        try {
            [$book, $aiPages] = DB::transaction(fn () => $this->write($user, $data, $pdf, $pages, $cover));
        } catch (Throwable $e) {
            Storage::disk('private')->delete($this->written);

            throw $e;
        }

        foreach ($aiPages as $pageId) {
            ImproveScanPage::dispatch($pageId);
        }

        return $book;
    }

    /**
     * @param  array{title: string, author: string, description?: string|null, department_id: int, level: string, text?: string|null, page_count?: int|null, page_text?: array<int, string|null>}  $data
     * @param  list<UploadedFile>  $pages
     * @return array{0: Book, 1: list<int>}
     */
    private function write(User $user, array $data, ?UploadedFile $pdf, array $pages, ?UploadedFile $cover): array
    {
        $book = new Book([
            'title' => $data['title'],
            'author' => $data['author'],
            'description' => $data['description'] ?? null,
            'department_id' => $data['department_id'],
            'level' => Level::from($data['level']),
        ]);
        $book->uploader_id = $user->id;
        $book->status = BookStatus::Pending;
        $book->save();

        $base = $book->public_id;
        $jpegs = [];
        $coverBytes = $cover ? (string) file_get_contents($cover->getRealPath()) : null;

        if ($pdf !== null) {
            $bytes = (string) file_get_contents($pdf->getRealPath());
            $pageCount = $data['page_count'] ?? null;
            $source = BookSource::Pdf;
            $pageTexts = [];
            $text = UploadText::clean((string) ($data['text'] ?? ''));
        } else {
            $jpegs = array_map(
                fn (UploadedFile $page) => Images::toJpeg(
                    (string) file_get_contents($page->getRealPath()),
                    (int) config('uploads.page_max_pixels'),
                    (int) config('uploads.page_quality'),
                ),
                $pages,
            );
            $bytes = ImagePdf::make($jpegs);
            $pageCount = count($jpegs);
            $source = BookSource::Scan;
            $pageTexts = array_map(fn ($i) => UploadText::clean((string) ($data['page_text'][$i] ?? '')), array_keys($jpegs));
            $text = UploadText::clean(implode("\n\n", array_filter($pageTexts)));
            // No cover given: the first page is the cover.
            $coverBytes ??= $jpegs[0]['data'];
        }

        $this->put("books/{$base}.pdf", $bytes);

        if ($coverBytes !== null) {
            $this->put("covers/{$base}.jpg", Images::toJpeg($coverBytes, self::COVER_PIXELS, 82)['data']);
            $book->cover_path = "covers/{$base}.jpg";
        }

        $ai = $source === BookSource::Scan && PageReader::enabled();

        $book->page_count = $pageCount;
        $book->save();
        $book->setSearchText($text);

        $file = $book->files()->create([
            'disk' => 'private',
            'path' => "books/{$base}.pdf",
            'original_name' => $pdf ? mb_substr($pdf->getClientOriginalName(), 0, 200) : null,
            'mime' => 'application/pdf',
            'size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'page_count' => $pageCount,
            'text_status' => match (true) {
                $ai => TextStatus::Queued,
                $text !== '' => TextStatus::Device,
                default => TextStatus::None,
            },
            'source' => $source,
            'is_current' => true,
        ]);

        // With AI on, each page is kept until it has been read again.
        $aiPages = [];
        if ($ai) {
            foreach ($jpegs as $i => $jpeg) {
                $path = sprintf('scans/%s/page-%03d.jpg', $base, $i + 1);
                $this->put($path, $jpeg['data']);

                $page = new BookScanPage(['page' => $i + 1, 'path' => $path, 'text' => $pageTexts[$i] ?: null]);
                $page->book_file_id = $file->id;
                $page->save();
                $aiPages[] = $page->id;
            }
        }

        return [$book, $aiPages];
    }

    private function put(string $path, string $contents): void
    {
        Storage::disk('private')->put($path, $contents);
        $this->written[] = $path;
    }
}
