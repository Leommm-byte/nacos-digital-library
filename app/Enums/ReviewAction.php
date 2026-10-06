<?php

namespace App\Enums;

/**
 * Entries in a book's moderation history (book_reviews).
 */
enum ReviewAction: string
{
    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';
    case Rejected = 'rejected';

    /** The uploader edited the book after changes were requested. */
    case Resubmitted = 'resubmitted';

    public function resultingStatus(): BookStatus
    {
        return match ($this) {
            self::Approved => BookStatus::Approved,
            self::ChangesRequested => BookStatus::ChangesRequested,
            self::Rejected => BookStatus::Rejected,
            self::Resubmitted => BookStatus::Pending,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Approved',
            self::ChangesRequested => 'Changes requested',
            self::Rejected => 'Rejected',
            self::Resubmitted => 'Resubmitted',
        };
    }

    /**
     * Decisions a reviewer can make (resubmitting is the uploader's).
     *
     * @return list<self>
     */
    public static function decisions(): array
    {
        return [self::Approved, self::ChangesRequested, self::Rejected];
    }
}
