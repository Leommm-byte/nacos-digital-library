<?php

namespace App\Http\Controllers\Review;

use App\Enums\BookStatus;
use App\Enums\Level;
use App\Enums\ReviewAction;
use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Department;
use App\Models\User;
use App\Support\Moderation\AlreadyReviewed;
use App\Support\Moderation\ReviewDecision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The review queue for governors and admins: uploads waiting for review
 * (and, with filters, every upload), each with a preview and a decision.
 */
class ReviewController extends Controller
{
    public const STATUSES = [
        'pending' => 'Waiting',
        'changes_requested' => 'Changes requested',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'all' => 'All',
    ];

    public function index(Request $request): View
    {
        $level = Level::tryFrom($request->string('level')->toString());

        $filters = [
            'status' => array_key_exists($request->string('status')->toString(), self::STATUSES) ? $request->string('status')->toString() : 'pending',
            'level' => $level !== null ? $level->value : '',
            'department' => $request->integer('department'),
            'q' => mb_substr(trim($request->string('q')->toString()), 0, 100),
        ];

        $books = Book::query()
            ->with(['department:id,name', 'uploader:id,fullname,matric_number', 'currentFile:id,book_id,source,size_bytes'])
            ->when($filters['status'] !== 'all', fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($filters['level'] !== '', fn (Builder $q) => $q->where('level', $filters['level']))
            ->when($filters['department'] > 0, fn (Builder $q) => $q->where('department_id', $filters['department']))
            ->when($filters['q'] !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('title', 'like', '%'.addcslashes($filters['q'], '%_\\').'%')
                ->orWhere('author', 'like', '%'.addcslashes($filters['q'], '%_\\').'%')))
            // Oldest first while waiting (first come, first served); newest
            // first for everything else.
            ->when($filters['status'] === 'pending', fn (Builder $q) => $q->oldest(), fn (Builder $q) => $q->latest())
            ->paginate(20)
            ->withQueryString();

        return view('review.index', [
            'books' => $books,
            'filters' => $filters,
            'counts' => Book::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all(),
            'levels' => collect(Level::cases())->mapWithKeys(fn (Level $level) => [$level->value => $level->label()])->all(),
            'departments' => Department::orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }

    public function show(Request $request, Book $book): View
    {
        abort_unless(Gate::allows('view', $book), 404);

        $book->load([
            'department:id,name',
            'uploader:id,fullname,matric_number,level',
            'currentFile',
            'reviews' => fn ($q) => $q->with('reviewer:id,fullname')->latest('id'),
        ]);

        /** @var User $user */
        $user = $request->user();

        return view('review.show', [
            'book' => $book,
            'canReview' => $book->status === BookStatus::Pending && $user->can('review', $book),
            'ownUpload' => $book->uploader_id === $user->id,
            'canDelete' => $user->can('delete', $book),
            'fileUrl' => $book->currentFile ? route('books.file', ['book' => $book, 'v' => substr($book->currentFile->sha256, 0, 12)]) : null,
            'nextPending' => $this->nextPending($user, $book)?->public_id,
        ]);
    }

    public function decide(Request $request, Book $book): RedirectResponse
    {
        abort_unless($request->user()?->can('review', $book), 403);

        $data = $request->validate([
            'action' => ['required', Rule::in(array_map(fn (ReviewAction $a) => $a->value, ReviewAction::decisions()))],
            'comment' => ['nullable', 'string', 'max:1000', 'required_unless:action,approved'],
        ], [
            'comment.required_unless' => 'Tell the uploader what to change or why it can\'t be added.',
        ]);

        /** @var User $reviewer */
        $reviewer = $request->user();
        $action = ReviewAction::from($data['action']);

        try {
            ReviewDecision::make($book, $reviewer, $action, $data['comment'] ?? null);
        } catch (AlreadyReviewed) {
            return redirect()->route('review.show', $book)
                ->withErrors(['action' => 'Someone else has already reviewed this upload.']);
        }

        $next = $this->nextPending($reviewer, $book);

        return ($next ? redirect()->route('review.show', $next) : redirect()->route('review.index'))
            ->with('status', match ($action) {
                ReviewAction::Approved => "Approved \"{$book->title}\".",
                ReviewAction::ChangesRequested => "Asked for changes to \"{$book->title}\".",
                default => "Rejected \"{$book->title}\".",
            });
    }

    /**
     * The oldest other upload this reviewer can decide (not their own).
     */
    private function nextPending(User $reviewer, Book $current): ?Book
    {
        return Book::where('status', BookStatus::Pending)
            ->whereKeyNot($current->id)
            ->where(fn (Builder $q) => $q->whereNull('uploader_id')->orWhere('uploader_id', '!=', $reviewer->id))
            ->oldest()
            ->first(['id', 'public_id']);
    }
}
