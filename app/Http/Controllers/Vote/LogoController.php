<?php

namespace App\Http\Controllers\Vote;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The department's logo, shown at the top of its pages. A new logo gets a
 * new file name, so the versioned address can be kept for good.
 */
class LogoController extends Controller
{
    public function __invoke(Request $request, Organization $organization): Response
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('private');

        abort_if($organization->logo_path === null || ! $disk->exists($organization->logo_path), 404);

        $current = $request->query('v') === substr(sha1($organization->logo_path), 0, 10);

        return $disk->response($organization->logo_path, null, [
            'Cache-Control' => $current ? 'public, max-age=31536000, immutable' : 'public, no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
