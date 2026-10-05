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
 * The URL carries a version (?v=) taken from the file's hash. A matching
 * version may be cached privately for a year; a new upload changes it.
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

        if ($request->query('v') === substr($file->sha256, 0, 12)) {
            $response->setMaxAge(31536000);
            $response->setImmutable();
        } else {
            $response->headers->addCacheControlDirective('no-cache');
        }

        return $response;
    }
}
