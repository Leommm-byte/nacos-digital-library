<?php

namespace App\Policies;

use App\Enums\BookStatus;
use App\Enums\Role;
use App\Models\Book;
use App\Models\User;

/**
 * Who may see and change a book. As in the original app, only approved
 * books are public to students; uploaders see their own submissions,
 * governors see their own level's (the ones they review) and admins see
 * everything.
 */
class BookPolicy
{
    public function view(User $user, Book $book): bool
    {
        return $book->status === BookStatus::Approved
            || $this->isUploader($user, $book)
            || $this->looksAfter($user, $book);
    }

    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Uploaders may fix their submission until it is approved; after that
     * only an admin can change it.
     */
    public function update(User $user, Book $book): bool
    {
        if ($user->hasRole(Role::Admin)) {
            return true;
        }

        return $this->isUploader($user, $book)
            && in_array($book->status, [BookStatus::Pending, BookStatus::ChangesRequested], true);
    }

    /**
     * Admins can delete any book; uploaders can delete theirs until it's in
     * the library (including after it was rejected).
     */
    public function delete(User $user, Book $book): bool
    {
        if ($user->hasRole(Role::Admin)) {
            return true;
        }

        return $this->isUploader($user, $book) && $book->status !== BookStatus::Approved;
    }

    /**
     * Approve, reject or request changes: governors for their own level,
     * admins for every level. Nobody reviews their own upload.
     */
    public function review(User $user, Book $book): bool
    {
        return $this->looksAfter($user, $book) && ! $this->isUploader($user, $book);
    }

    private function looksAfter(User $user, Book $book): bool
    {
        return $user->hasRole(Role::Admin) || ($user->hasRole(Role::Governor) && $book->level === $user->level);
    }

    private function isUploader(User $user, Book $book): bool
    {
        return $book->uploader_id !== null && $book->uploader_id === $user->id;
    }
}
