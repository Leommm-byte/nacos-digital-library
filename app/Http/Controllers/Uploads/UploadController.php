<?php

namespace App\Http\Controllers\Uploads;

use App\Enums\Level;
use App\Enums\Role;
use App\Enums\TextStatus;
use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Department;
use App\Models\User;
use App\Support\Audit;
use App\Support\Catalog;
use App\Support\Uploads\BookUpload;
use App\Support\Uploads\UnreadableImage;
use App\Support\Uploads\UploadLimits;
use App\Support\Uploads\VirusScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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

        $url = route('uploads.show', $book);

        return $request->expectsJson()
            ? response()->json(['redirect' => $url], 201)
            : redirect($url)->with('uploaded', true);
    }

    /**
     * The upload's status page (also the "thank you" page after uploading).
     */
    public function show(Request $request, Book $book): View
    {
        $this->authorizeOwner($request, $book);

        $book->load(['department:id,name', 'currentFile']);

        return view('uploads.show', [
            'book' => $book,
            'text' => $this->textProgress($book),
            'justUploaded' => (bool) $request->session()->get('uploaded'),
        ]);
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
