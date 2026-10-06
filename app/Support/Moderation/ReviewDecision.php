<?php

namespace App\Support\Moderation;

use App\Enums\BookStatus;
use App\Enums\ReviewAction;
use App\Models\Book;
use App\Models\User;
use App\Notifications\UploadReviewed;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Approves, rejects or returns an upload. The book is locked and checked to
 * still be waiting for review, so two reviewers can't both decide it; the
 * decision goes into the book's history and the audit log, and the
 * uploader is told (in the app, and by email if theirs is verified).
 */
class ReviewDecision
{
    public static function make(Book $book, User $reviewer, ReviewAction $action, ?string $comment): Book
    {
        $book = DB::transaction(function () use ($book, $reviewer, $action, $comment) {
            /** @var Book $locked */
            $locked = Book::whereKey($book->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== BookStatus::Pending) {
                throw new AlreadyReviewed;
            }

            $locked->status = $action->resultingStatus();
            $locked->reviewed_by = $reviewer->id;
            if ($action === ReviewAction::Approved) {
                $locked->approved_at = now();
            }
            $locked->save();

            $review = $locked->reviews()->make(['action' => $action, 'comment' => $comment]);
            $review->reviewer_id = $reviewer->id;
            $review->save();

            return $locked;
        });

        Audit::record('book_'.$action->value, $book, array_filter(['comment' => $comment]));

        $book->uploader?->notify(new UploadReviewed($book, $action, $comment));

        return $book;
    }
}
