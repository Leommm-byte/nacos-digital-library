<?php

namespace App\Support\Assistant;

use App\Enums\ElectionStatus;
use App\Models\Book;
use App\Models\Election;
use App\Models\ReadingProgress;
use App\Models\User;
use App\Support\Catalog;
use App\Support\Dashboard\ActivityFeed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

/**
 * What the assistant can look up, for both the free helper and the AI: the
 * library, and the signed-in student's own uploads, saved books, reading,
 * notifications, activity and elections. Never anyone else's data.
 *
 * Each row has a "label" and "url" for the link shown under the answer.
 */
class Lookups
{
    public function __construct(private User $user) {}

    /**
     * Approved books matching a search, best first.
     *
     * @return list<array{id: string, label: string, url: string, note: string, author: string, level: string, department: string}>
     */
    public function books(string $search, ?string $level = null, int $limit = 5): array
    {
        return Catalog::query(['q' => $search, 'level' => $level])
            ->limit($limit)
            ->get()
            ->map(fn (Book $book) => [
                'id' => $book->public_id,
                'label' => $book->title,
                'url' => route('library.show', $book),
                'note' => $book->author.' · '.$book->level->label(),
                'author' => $book->author,
                'level' => $book->level->label(),
                'department' => $book->department->name,
            ])
            ->values()
            ->all();
    }

    /**
     * An approved book in an open department, by its public id.
     */
    public function book(string $id): ?Book
    {
        return Book::query()
            ->approved()
            ->whereIn('department_id', Catalog::activeDepartmentIds())
            ->where('public_id', $id)
            ->with(['text', 'department:id,name'])
            ->first();
    }

    /**
     * Passages of a book's text, for explaining, summarising or quizzing.
     * With a focus, the passages that mention its words most; without,
     * the start of the book.
     */
    public function excerpt(Book $book, ?string $focus = null, int $max = 6000): string
    {
        $text = trim((string) $book->text?->text);

        if ($text === '') {
            return '';
        }

        $words = collect(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) $focus)) ?: [])
            ->filter(fn (string $word) => mb_strlen($word) >= 3)
            ->unique()
            ->values()
            ->all();

        if ($words === [] || mb_strlen($text) <= $max) {
            return mb_substr($text, 0, $max);
        }

        // Pieces of about 1,200 characters, split at paragraph breaks.
        $pieces = [];
        $piece = '';
        foreach (preg_split('/\n\s*\n/u', $text) ?: [] as $paragraph) {
            foreach (mb_str_split($paragraph, 1200) as $part) {
                if ($piece !== '' && mb_strlen($piece) + mb_strlen($part) > 1200) {
                    $pieces[] = $piece;
                    $piece = '';
                }
                $piece .= ($piece === '' ? '' : "\n\n").$part;
            }
        }
        if ($piece !== '') {
            $pieces[] = $piece;
        }

        $scores = [];
        foreach ($pieces as $index => $part) {
            $lower = mb_strtolower($part);
            $scores[$index] = array_sum(array_map(fn (string $word) => mb_substr_count($lower, $word), $words));
        }

        arsort($scores);
        $chosen = [];
        $length = 0;
        foreach ($scores as $index => $score) {
            if ($score === 0 || $length + mb_strlen($pieces[$index]) > $max) {
                continue;
            }
            $chosen[] = $index;
            $length += mb_strlen($pieces[$index]);
        }

        if ($chosen === []) {
            return mb_substr($text, 0, $max);
        }

        sort($chosen);

        return implode("\n\n[…]\n\n", array_map(fn (int $index) => $pieces[$index], $chosen));
    }

    /**
     * @return list<array{label: string, url: string, note: string, status: string, comment: string|null}>
     */
    public function uploads(int $limit = 5): array
    {
        return Book::query()
            ->where('uploader_id', $this->user->id)
            ->with(['reviews' => fn ($q) => $q->latest('id')])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(function (Book $book) {
                $comment = $book->reviews->first()?->comment;

                return [
                    'label' => $book->title,
                    'url' => route('uploads.show', $book),
                    'note' => $book->status->label(),
                    'status' => $book->status->label(),
                    'comment' => is_string($comment) && $comment !== '' ? $comment : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{label: string, url: string, note: string}>
     */
    public function saved(int $limit = 5): array
    {
        return $this->user->bookmarks()
            ->approved()
            ->orderByPivot('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn (Book $book) => [
                'label' => $book->title,
                'url' => route('library.show', $book),
                'note' => $book->author,
            ])
            ->values()
            ->all();
    }

    /**
     * Books started and not finished, most recent first.
     *
     * @return list<array{label: string, url: string, note: string}>
     */
    public function reading(int $limit = 3): array
    {
        return ReadingProgress::query()
            ->where('user_id', $this->user->id)
            ->whereNull('completed_at')
            ->whereHas('book', fn (Builder $q) => $q->approved())
            ->with('book')
            ->latest('last_read_at')
            ->limit($limit)
            ->get()
            ->map(fn (ReadingProgress $progress) => [
                'label' => $progress->book->title,
                'url' => route('books.read', $progress->book),
                'note' => 'Page '.$progress->current_page.' · '.$progress->progress_percent.'% read',
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{label: string, url: string, note: string, unread: bool}>
     */
    public function notifications(int $limit = 5): array
    {
        return $this->user->notifications()
            ->limit($limit)
            ->get()
            ->map(fn (DatabaseNotification $notification) => [
                'label' => (string) ($notification->data['headline'] ?? 'Notification'),
                'url' => route('notifications.open', $notification->id),
                'note' => ($notification->read_at === null ? 'New · ' : '').$notification->created_at?->diffForHumans(),
                'unread' => $notification->read_at === null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{text: string, when: string}>
     */
    public function activity(int $limit = 5): array
    {
        return array_map(fn (array $entry) => [
            'text' => $entry['text'],
            'when' => $entry['at']->diffForHumans(),
        ], ActivityFeed::forUser($this->user, $limit));
    }

    /**
     * Open elections, whether this student can vote and whether they have.
     *
     * @return list<array{label: string, url: string, note: string, can_vote: bool, voted: bool, reason: string|null}>
     */
    public function elections(): array
    {
        $open = Election::query()
            ->where('status', ElectionStatus::Open)
            ->where('ends_at', '>', now())
            ->orderBy('ends_at')
            ->limit(5)
            ->get();

        $voted = DB::table('election_voters')
            ->where('user_id', $this->user->id)
            ->whereIn('election_id', $open->pluck('id'))
            ->pluck('election_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $open->map(function (Election $election) use ($voted) {
            $hasVoted = in_array($election->id, $voted, true);
            $reason = $hasVoted ? null : $election->ineligibilityReason($this->user);

            return [
                'label' => $election->title,
                'url' => route('elections.show', $election),
                'note' => match (true) {
                    $hasVoted => 'You have voted',
                    $reason !== null => 'You can\'t vote in this one',
                    default => 'Open until '.($election->ends_at?->timezone((string) config('app.display_timezone'))->format('j M, g:ia') ?? 'further notice'),
                },
                'can_vote' => ! $hasVoted && $reason === null,
                'voted' => $hasVoted,
                'reason' => $reason,
            ];
        })->values()->all();
    }
}
