<?php

namespace App\Http\Controllers;

use App\Enums\BookStatus;
use App\Models\Book;
use App\Models\ReadingProgress;
use App\Models\User;
use App\Support\Catalog;
use App\Support\Dashboard\ActivityFeed;
use App\Support\Dashboard\Announcements;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Home: a landing page for guests; for students, the dashboard (greeting,
 * stats, continue reading, recommendations, saved books, announcements,
 * their profile and recent activity).
 *
 * About a dozen small indexed queries; the shared parts (recommendations
 * per class, live announcements) are cached for everyone.
 */
class HomeController extends Controller
{
    /** Books per shelf on the dashboard: one row of four. */
    public const SHELF = 4;

    public function __invoke(Request $request): View
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return view('home.guest');
        }

        $user->loadMissing('department:id,name');

        $reading = ReadingProgress::query()
            ->where('user_id', $user->id)
            ->whereHas('book', fn (Builder $q) => $q->approved())
            ->with('book')
            ->latest('last_read_at')
            ->get(['user_id', 'book_id', 'current_page', 'progress_percent', 'completed_at', 'last_read_at']);

        $continue = $reading->whereNull('completed_at')->take(2)->values();
        $opened = $reading->pluck('book_id')->all();

        $saved = $user->bookmarks()->approved()->orderByPivot('created_at', 'desc')->limit(self::SHELF)->get();
        $savedCount = $user->bookmarks()->approved()->count();

        $uploads = Book::where('uploader_id', $user->id)
            ->toBase()
            ->selectRaw('count(*) as total, sum(case when status = ? then 1 else 0 end) as approved', [BookStatus::Approved->value])
            ->first();

        return view('home.student', [
            'user' => $user,
            'greeting' => $this->greeting(),
            'continue' => $continue,
            'recommended' => $this->recommended($user, $opened),
            'saved' => $saved,
            'savedIds' => $user->bookmarks()->pluck('books.id')->mapWithKeys(fn ($id) => [(int) $id => true])->all(),
            'stats' => [
                'opened' => $reading->count(),
                'finished' => $reading->whereNotNull('completed_at')->count(),
                'saved' => $savedCount,
                'uploads' => (int) ($uploads->total ?? 0),
                'approved' => (int) ($uploads->approved ?? 0),
                'unread' => $user->unreadNotifications()->count(),
            ],
            'announcements' => Announcements::latest(3),
            'activity' => ActivityFeed::forUser($user),
            'waiting' => Gate::allows('review-uploads')
                ? Book::where('status', BookStatus::Pending)->where('uploader_id', '!=', $user->id)->count()
                : null,
        ]);
    }

    /**
     * Newest books for the student's department and level, then for the
     * same level elsewhere, skipping books they've already opened. The
     * candidate list is shared by everyone in the class and cached.
     *
     * @param  list<int>  $opened
     * @return Collection<int, Book>
     */
    private function recommended(User $user, array $opened): Collection
    {
        /** @var list<int> $ids */
        $ids = Cache::remember("home:recommended:{$user->department_id}:{$user->level->value}", 600, function () use ($user) {
            $base = fn () => Book::approved()
                ->whereIn('department_id', Catalog::activeDepartmentIds())
                ->where('level', $user->level)
                ->latest('approved_at')
                ->limit(24);

            return $base()->where('department_id', $user->department_id)->pluck('id')
                ->concat($base()->where('department_id', '!=', $user->department_id)->pluck('id'))
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        });

        $ids = array_slice(array_values(array_diff($ids, $opened)), 0, self::SHELF);

        if ($ids === []) {
            return collect();
        }

        $books = Book::approved()->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn (int $id) => $books->get($id))->filter()->values();
    }

    private function greeting(): string
    {
        $hour = (int) now()->timezone((string) config('app.display_timezone'))->format('G');

        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
    }
}
