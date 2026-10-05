<?php

namespace App\Http\Controllers\Reader;

use App\Enums\BookStatus;
use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\ReadingProgress;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The reading screen. The PDF itself is fetched by PDF.js from
 * BookFileController, a page range at a time.
 */
class ReaderController extends Controller
{
    public function show(Request $request, Book $book): View
    {
        // Checked before anything is recorded, so hidden books leave no trace.
        abort_unless(Gate::allows('view', $book), 404);

        /** @var User $user */
        $user = $request->user();
        $file = $book->currentFile;
        $approved = $book->status === BookStatus::Approved;

        if ($approved && $file !== null) {
            $this->recordRead($request, $user, $book);
        }

        $progress = ReadingProgress::query()
            ->where('user_id', $user->id)
            ->where('book_id', $book->id)
            ->first();

        return view('library.reader', [
            'book' => $book,
            'file' => $file,
            'approved' => $approved,
            'fileUrl' => $file ? route('books.file', ['book' => $book, 'v' => substr($file->sha256, 0, 12)]) : null,
            'startPage' => $progress->current_page ?? 1,
            'watermark' => $user->matric_number,
        ]);
    }

    /**
     * Counts one read per book per login session, and puts the book at the
     * top of the reader's history.
     */
    private function recordRead(Request $request, User $user, Book $book): void
    {
        $key = 'reader.opened.'.$book->id;

        if (! $request->session()->has($key)) {
            $request->session()->put($key, true);
            // Through the query builder so the book's updated_at is untouched.
            DB::table('books')->where('id', $book->id)->increment('views_count');
        }

        ReadingProgress::upsert(
            [['user_id' => $user->id, 'book_id' => $book->id, 'last_read_at' => now()]],
            ['user_id', 'book_id'],
            ['last_read_at'],
        );
    }
}
