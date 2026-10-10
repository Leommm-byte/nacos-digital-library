<?php

namespace App\Http\Controllers\Elections;

use App\Enums\ElectionStatus;
use App\Http\Controllers\Controller;
use App\Models\Election;
use App\Models\ElectionCandidate;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Candidate photos, shown on the ballot and the public results. Like the
 * results, they are public once voting opens; a draft's photos are only
 * shown to the admins setting it up.
 */
class CandidatePhotoController extends Controller
{
    public function __invoke(Request $request, Election $election, ElectionCandidate $candidate): Response
    {
        $draft = $election->status === ElectionStatus::Draft;

        abort_unless($election->positions()->whereKey($candidate->position_id)->exists(), 404);
        abort_if($draft && ! Gate::allows('manage-elections'), 404);

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('private');

        abort_if($candidate->photo_path === null || ! $disk->exists($candidate->photo_path), 404);

        $current = $request->query('v') === substr(sha1($candidate->photo_path), 0, 10);

        return $disk->response($candidate->photo_path, null, [
            // A matching version never changes: a new photo gets a new path.
            'Cache-Control' => $current && ! $draft ? 'public, max-age=31536000, immutable' : 'private, no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
