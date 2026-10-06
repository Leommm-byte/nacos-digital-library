<?php

namespace App\Support;

use App\Models\Book;
use App\Models\BookScanPage;
use App\Models\BookText;
use App\Models\ReadingProgress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes a book completely: its PDF files, cover, scanned pages, text,
 * bookmarks and reading history. The book row itself is soft-deleted, so
 * the audit log and the uploader's daily upload count still see it.
 */
class BookRemover
{
    public static function remove(Book $book): void
    {
        $paths = $book->files()->pluck('path')->all();
        $fileIds = $book->files()->pluck('id')->all();
        $scanPaths = BookScanPage::whereIn('book_file_id', $fileIds)->whereNotNull('path')->pluck('path')->all();

        if ($book->cover_path !== null) {
            $paths[] = $book->cover_path;
        }

        DB::transaction(function () use ($book, $fileIds) {
            BookScanPage::whereIn('book_file_id', $fileIds)->delete();
            $book->files()->delete();
            BookText::where('book_id', $book->id)->delete();
            ReadingProgress::where('book_id', $book->id)->delete();
            DB::table('bookmarks')->where('book_id', $book->id)->delete();

            $book->cover_path = null;
            $book->save();
            $book->delete();
        });

        // Files go after the database changes are committed.
        Storage::disk('private')->delete([...$paths, ...$scanPaths]);
        Storage::disk('private')->deleteDirectory('scans/'.$book->public_id);
    }
}
