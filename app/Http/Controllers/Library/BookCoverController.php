<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\Book;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Covers live on the private disk and are only served to people allowed to
 * see the book. URLs carry a version (?v=) that changes with the cover, so
 * browsers may keep them for a year without asking again.
 */
class BookCoverController extends Controller
{
    public function __invoke(Request $request, Book $book): Response
    {
        abort_unless(Gate::allows('view', $book), 404);

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('private');

        abort_if($book->cover_path === null || ! $disk->exists($book->cover_path), 404);

        return $disk->response($book->cover_path, null, [
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
