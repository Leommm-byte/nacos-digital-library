<?php

namespace App\Http\Controllers\Reader;

use App\Http\Controllers\Controller;
use App\Models\Book;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Streams a book's PDF to the reader. Supports HTTP Range requests, so
 * PDF.js fetches only the pages being read instead of the whole file
 * before showing page 1.
 *
 * The browser may keep a copy but must check with the server before using
 * it (ETag, answered with an empty 304 while the file is unchanged). So a
 * book is never downloaded twice, and nobody can open a cached copy once
 * the student has logged out on a shared phone. Books kept for offline
 * reading live in the service worker's cache instead (resources/sw).
 */
class BookFileController extends Controller
{
    public function __invoke(Request $request, Book $book): BinaryFileResponse
    {
        abort_unless(Gate::allows('view', $book), 404);

        $file = $book->currentFile;
        abort_if($file === null, 404);

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($file->disk);
        abort_unless($disk->exists($file->path), 404);

        $response = new BinaryFileResponse($disk->path($file->path), 200, [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
        ], public: false, autoLastModified: false);

        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'book.pdf');
        $response->setEtag($file->sha256);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-cache');
        $response->isNotModified($request);

        return $response;
    }
}
