<?php

namespace App\Http\Controllers\Uploads;

use App\Enums\BookSource;
use App\Enums\BookStatus;
use App\Enums\Level;
use App\Enums\ReviewAction;
use App\Enums\Role;
use App\Enums\TextStatus;
use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Department;
use App\Models\User;
use App\Support\Audit;
use App\Support\BookRemover;
use App\Support\Catalog;
use App\Support\Uploads\BookUpload;
use App\Support\Uploads\Images;
use App\Support\Uploads\UnreadableImage;
use App\Support\Uploads\UploadLimits;
use App\Support\Uploads\VirusScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Sharing a book: as a PDF, or as photos of its pages that are compiled
 * into one. Uploads wait for review before anyone else can see them.
 *
 * The upload page's script (resources/js/upload.js) does the heavy work on
 * the student's device first: it shrinks photos, reads their text (OCR),
 * pulls the text and a cover out of PDFs, and shows upload progress. The
 * form still works without it.
 */
class UploadController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('uploads.index', [
            'books' => Book::where('uploader_id', $user->id)
                ->with('department:id,name')
                ->latest()
                ->paginate(20),
        ]);
    }

    public function create(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('uploads.create', [
            'user' => $user,
            'blocked' => UploadLimits::blockReason($user),
            'remaining' => UploadLimits::remainingToday($user),
            'levels' => collect(Level::cases())->mapWithKeys(fn (Level $level) => [$level->value => $level->label()])->all(),
            'departments' => Department::whereIn('id', Catalog::activeDepartmentIds())->orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }

    public function store(Request $request, BookUpload $upload): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($reason = UploadLimits::blockReason($user)) {
            throw ValidationException::withMessages(['upload' => $reason]);
        }

        $size = fn (string $key) => Number::fileSize((int) config($key) * 1024);

        $data = $request->validate($this->rules(), [
            'pdf.max' => 'The PDF must be '.$size('uploads.pdf_max_kb').' or smaller.',
            'pdf.required' => 'Choose a PDF to upload.',
            'pdf.mimetypes' => 'Choose a PDF file.',
            'pages.required' => 'Add at least one photo of a page.',
            'pages.max' => 'Add at most :max pages. Split longer books into parts.',
            'pages.*.max' => 'Each page photo must be '.$size('uploads.page_max_kb').' or smaller.',
            'pages.*.mimes' => 'Page photos must be JPG, PNG or WebP images.',
            'pages.*.mimetypes' => 'Page photos must be JPG, PNG or WebP images.',
            'cover.max' => 'The cover must be '.$size('uploads.cover_max_kb').' or smaller.',
            'department_id.in' => 'Choose one of the listed departments.',
        ]);

        /** @var UploadedFile|null $pdf */
        $pdf = $data['type'] === 'pdf' ? $request->file('pdf') : null;
        /** @var list<UploadedFile> $pages */
        $pages = $data['type'] === 'scan' ? array_values((array) $request->file('pages')) : [];
        /** @var UploadedFile|null $cover */
        $cover = $request->file('cover');

        // Checked by content, not by name or the browser's claim.
        if ($pdf !== null && (string) file_get_contents($pdf->getRealPath(), length: 5) !== '%PDF-') {
            throw ValidationException::withMessages(['pdf' => 'This file isn\'t a PDF.']);
        }

        foreach (array_filter([$pdf, $cover, ...$pages]) as $file) {
            $result = VirusScanner::scan($file->getRealPath());
            if ($result !== VirusScanner::CLEAN) {
                throw ValidationException::withMessages(['upload' => $result === VirusScanner::INFECTED
                    ? 'This upload was flagged as unsafe and wasn\'t saved.'
                    : 'We couldn\'t check this upload for viruses. Please try again.']);
            }
        }

        try {
            $book = $upload->store($user, [
                'title' => $data['title'],
                'author' => $data['author'],
                'description' => $data['description'] ?? null,
                'department_id' => (int) $data['department_id'],
                'level' => $data['level'],
                'text' => $data['text'] ?? null,
                'page_count' => isset($data['page_count']) ? (int) $data['page_count'] : null,
                'page_text' => $data['page_text'] ?? [],
            ], $pdf, $pages, $cover);
        } catch (UnreadableImage) {
            // GD couldn't read one of the images.
            throw ValidationException::withMessages([
                $pdf !== null ? 'cover' : 'pages' => 'One of the images couldn\'t be read. Try saving it as a JPG.',
            ]);
        }

        Audit::record('book_uploaded', $book);

        // Shown once on the next page: the thank-you note.
        $request->session()->flash('uploaded', true);
        $url = route('uploads.show', $book);

        return $request->expectsJson()
            ? response()->json(['redirect' => $url], 201)
            : redirect($url);
    }

    /**
     * The upload's status page (also the "thank you" page after uploading).
     */
    public function show(Request $request, Book $book): View
    {
        $this->authorizeOwner($request, $book);

        $book->load(['department:id,name', 'currentFile', 'reviews' => fn ($q) => $q->latest('id')->limit(1)]);

        return view('uploads.show', [
            'book' => $book,
            'text' => $this->textProgress($book),
            'justUploaded' => (bool) $request->session()->get('uploaded'),
        ]);
    }

    /**
     * Fix an upload while it waits for review, or after a reviewer asked for
     * changes (saving then sends it back for review).
     */
    public function edit(Request $request, Book $book): View
    {
        abort_unless($request->user()?->can('update', $book), 404);

        $book->load(['currentFile', 'reviews' => fn ($q) => $q->latest('id')->limit(1)]);

        return view('uploads.edit', [
            'book' => $book,
            'changes' => $book->status === BookStatus::ChangesRequested ? $book->reviews->first() : null,
            'levels' => collect(Level::cases())->mapWithKeys(fn (Level $level) => [$level->value => $level->label()])->all(),
            'departments' => Department::whereIn('id', Catalog::activeDepartmentIds())->orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }

    public function update(Request $request, Book $book): RedirectResponse
    {
        abort_unless($request->user()?->can('update', $book), 404);

        $size = fn (string $key) => Number::fileSize((int) config($key) * 1024);
        $images = ['mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp'];

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'author' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'level' => ['required', Rule::enum(Level::class)],
            'department_id' => ['required', 'integer', Rule::in(Catalog::activeDepartmentIds())],
            'cover' => ['nullable', 'file', ...$images, 'max:'.config('uploads.cover_max_kb')],
            'pdf' => ['nullable', 'file', 'mimetypes:application/pdf', 'max:'.config('uploads.pdf_max_kb')],
        ], [
            'pdf.max' => 'The PDF must be '.$size('uploads.pdf_max_kb').' or smaller.',
            'pdf.mimetypes' => 'Choose a PDF file.',
            'cover.max' => 'The cover must be '.$size('uploads.cover_max_kb').' or smaller.',
        ]);

        /** @var UploadedFile|null $pdf */
        $pdf = $request->file('pdf');
        /** @var UploadedFile|null $cover */
        $cover = $request->file('cover');

        if ($pdf !== null && (string) file_get_contents($pdf->getRealPath(), length: 5) !== '%PDF-') {
            throw ValidationException::withMessages(['pdf' => 'This file isn\'t a PDF.']);
        }

        foreach (array_filter([$pdf, $cover]) as $file) {
            if (VirusScanner::scan($file->getRealPath()) !== VirusScanner::CLEAN) {
                throw ValidationException::withMessages(['upload' => 'This file couldn\'t be accepted. Please try another.']);
            }
        }

        try {
            $coverJpeg = $cover ? Images::toJpeg((string) file_get_contents($cover->getRealPath()), 900, 82)['data'] : null;
        } catch (UnreadableImage) {
            throw ValidationException::withMessages(['cover' => 'This image couldn\'t be read. Try saving it as a JPG.']);
        }

        /** @var User $user */
        $user = $request->user();
        $disk = Storage::disk('private');
        $oldCover = $book->cover_path;
        $resubmitted = $book->status === BookStatus::ChangesRequested;

        DB::transaction(function () use ($book, $data, $pdf, $coverJpeg, $disk, $user, $resubmitted) {
            $book->fill([
                'title' => $data['title'],
                'author' => $data['author'],
                'description' => $data['description'] ?? null,
                'department_id' => (int) $data['department_id'],
                'level' => Level::from($data['level']),
            ]);

            if ($coverJpeg !== null) {
                // A new name, so browsers don't keep showing the old cover.
                $book->cover_path = 'covers/'.$book->public_id.'-'.now()->format('YmdHis').'.jpg';
                $disk->put($book->cover_path, $coverJpeg);
            }

            if ($pdf !== null) {
                $bytes = (string) file_get_contents($pdf->getRealPath());
                $file = $book->currentFile()->firstOrFail();
                $disk->put($file->path, $bytes);
                $file->forceFill([
                    'original_name' => mb_substr($pdf->getClientOriginalName(), 0, 200),
                    'size_bytes' => strlen($bytes),
                    'sha256' => hash('sha256', $bytes),
                    'page_count' => null,
                    'source' => BookSource::Pdf,
                    'text_status' => TextStatus::None,
                ])->save();
                $book->page_count = null;
                // The old text described the old file.
                $book->setSearchText(null);
            }

            if ($resubmitted) {
                $book->status = BookStatus::Pending;
                $review = $book->reviews()->make(['action' => ReviewAction::Resubmitted]);
                $review->reviewer_id = $user->id;
                $review->save();
            }

            $book->save();
        });

        if ($coverJpeg !== null && $oldCover !== null) {
            $disk->delete($oldCover);
        }

        Audit::record($resubmitted ? 'book_resubmitted' : 'book_edited', $book);

        return redirect()->route('uploads.show', $book)->with('status', $resubmitted
            ? 'Thanks! Your changes were sent for review.'
            : 'Your changes were saved.');
    }

    public function destroy(Request $request, Book $book): RedirectResponse
    {
        abort_unless($request->user()?->can('delete', $book), 404);

        BookRemover::remove($book);
        Audit::record('book_deleted', $book, ['title' => $book->title]);

        /** @var User $user */
        $user = $request->user();

        return redirect()->route($book->uploader_id === $user->id ? 'uploads.index' : 'review.index')
            ->with('status', "Deleted \"{$book->title}\".");
    }

    /**
     * Polled by the status page while the AI pass runs.
     */
    public function status(Request $request, Book $book): JsonResponse
    {
        $this->authorizeOwner($request, $book);

        return response()->json($this->textProgress($book));
    }

    /**
     * @return array{status: string, done: int, total: int}
     */
    private function textProgress(Book $book): array
    {
        $file = $book->currentFile()->first();

        if ($file === null) {
            return ['status' => TextStatus::None->value, 'done' => 0, 'total' => 0];
        }

        $total = $file->scanPages()->count();

        return [
            'status' => $file->text_status->value,
            'done' => $total - $file->scanPages()->whereNull('processed_at')->count(),
            'total' => $total,
        ];
    }

    private function authorizeOwner(Request $request, Book $book): void
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($book->uploader_id === $user->id || $user->hasRole(Role::Governor), 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        $images = ['mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp'];

        return [
            'type' => ['required', Rule::in(['pdf', 'scan'])],
            'title' => ['required', 'string', 'max:255'],
            'author' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'level' => ['required', Rule::enum(Level::class)],
            'department_id' => ['required', 'integer', Rule::in(Catalog::activeDepartmentIds())],
            'cover' => ['nullable', 'file', ...$images, 'max:'.config('uploads.cover_max_kb')],
            'pdf' => ['exclude_unless:type,pdf', 'required', 'file', 'mimetypes:application/pdf', 'max:'.config('uploads.pdf_max_kb')],
            'pages' => ['exclude_unless:type,scan', 'required', 'array', 'min:1', 'max:'.config('uploads.max_pages')],
            'pages.*' => ['exclude_unless:type,scan', 'file', ...$images, 'max:'.config('uploads.page_max_kb')],
            'page_count' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'text' => ['nullable', 'string', 'max:1000000'],
            'page_text' => ['nullable', 'array'],
            'page_text.*' => ['nullable', 'string', 'max:50000'],
        ];
    }
}
