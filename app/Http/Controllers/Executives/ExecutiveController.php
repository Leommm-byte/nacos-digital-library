<?php

namespace App\Http\Controllers\Executives;

use App\Http\Controllers\Controller;
use App\Models\Executive;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Know your executives": the current executives, public like the election
 * results, with their photos.
 */
class ExecutiveController extends Controller
{
    public function index(): View
    {
        return view('executives.index', [
            'executives' => Executive::query()->orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    public function photo(Request $request, Executive $executive): Response
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('private');

        abort_if($executive->photo_path === null || ! $disk->exists($executive->photo_path), 404);

        $current = $request->query('v') === substr(sha1($executive->photo_path), 0, 10);

        return $disk->response($executive->photo_path, null, [
            // A matching version never changes: a new photo gets a new path.
            'Cache-Control' => $current ? 'public, max-age=31536000, immutable' : 'public, no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
