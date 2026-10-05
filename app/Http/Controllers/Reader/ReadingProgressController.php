<?php

namespace App\Http\Controllers\Reader;

use App\Enums\BookStatus;
use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\ReadingProgress;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Saves where the reader is. Sent by the reader while reading and, with
 * navigator.sendBeacon, when the page is closed.
 *
 * The page is kept as sent (so the book reopens there), but the percentage
 * only moves forward and never goes above 100, and "completed" is recorded
 * once, at 95%.
 */
class ReadingProgressController extends Controller
{
    public const COMPLETED_AT_PERCENT = 95;

    public function store(Request $request, Book $book): Response
    {
        abort_unless(Gate::allows('view', $book), 404);

        $data = $request->validate([
            'page' => ['required', 'integer', 'min:1', 'max:100000'],
            'pages' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        $file = $book->currentFile;

        // Only approved books with a file build up reading history.
        if ($book->status !== BookStatus::Approved || $file === null) {
            return response()->noContent();
        }

        /** @var User $user */
        $user = $request->user();

        // The stored page count wins; the reader's count is a fallback for
        // files whose pages were never counted.
        $total = max(1, $file->page_count ?: (int) $data['pages']);
        $page = min((int) $data['page'], $total);
        $percent = (int) min(100, round($page / $total * 100));

        $existing = ReadingProgress::query()
            ->where('user_id', $user->id)
            ->where('book_id', $book->id)
            ->first();

        $percent = max($percent, $existing->progress_percent ?? 0);
        $completedAt = $existing->completed_at
            ?? ($percent >= self::COMPLETED_AT_PERCENT ? now() : null);

        ReadingProgress::upsert(
            [[
                'user_id' => $user->id,
                'book_id' => $book->id,
                'current_page' => $page,
                'progress_percent' => $percent,
                'completed_at' => $completedAt,
                'last_read_at' => now(),
            ]],
            ['user_id', 'book_id'],
            ['current_page', 'progress_percent', 'completed_at', 'last_read_at'],
        );

        return response()->noContent();
    }
}
