<?php

namespace App\Http\Controllers\Elections;

use App\Http\Controllers\Controller;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionPosition;
use App\Models\User;
use App\Support\Audit;
use App\Support\MatricNumber;
use App\Support\Uploads\Images;
use App\Support\Uploads\UnreadableImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Positions and candidates of a draft election. Routes are scoped, so a
 * position always belongs to the election in the URL and a candidate to
 * the position.
 */
class BallotSetupController extends Controller
{
    public function storePosition(Request $request, Election $election): RedirectResponse
    {
        Gate::authorize('update', $election);

        $data = $request->validateWithBag('position', ['title' => ['required', 'string', 'max:120']]);

        $position = $election->positions()->create([
            'title' => (string) $data['title'],
            'sort_order' => (int) $election->positions()->max('sort_order') + 1,
        ]);

        Audit::record('election_position_added', $election, ['position' => $position->title]);

        return $this->back($election, "position-{$position->id}")->with('status', "{$position->title} added.");
    }

    public function updatePosition(Request $request, Election $election, ElectionPosition $position): RedirectResponse
    {
        Gate::authorize('update', $election);

        $data = $request->validateWithBag("position-{$position->id}", ['title' => ['required', 'string', 'max:120']]);
        $position->update(['title' => (string) $data['title']]);

        return $this->back($election, "position-{$position->id}")->with('status', 'Position renamed.');
    }

    /**
     * Moves a position one place up or down the ballot.
     */
    public function movePosition(Request $request, Election $election, ElectionPosition $position): RedirectResponse
    {
        Gate::authorize('update', $election);

        $positions = $election->positions()->get()->values();
        $index = $positions->search(fn (ElectionPosition $item) => $item->id === $position->id);
        $target = $index === false ? null : $index + ($request->input('direction') === 'up' ? -1 : 1);

        if ($index !== false && $target !== null && $positions->has($target)) {
            $order = $positions->all();
            [$order[$index], $order[$target]] = [$order[$target], $order[$index]];

            foreach (array_values($order) as $sort => $item) {
                if ($item->sort_order !== $sort) {
                    $item->update(['sort_order' => $sort]);
                }
            }
        }

        return $this->back($election, "position-{$position->id}");
    }

    public function destroyPosition(Election $election, ElectionPosition $position): RedirectResponse
    {
        Gate::authorize('update', $election);

        $paths = $position->candidates()->whereNotNull('photo_path')->pluck('photo_path')->all();
        $position->delete();
        Storage::disk('private')->delete($paths);
        Audit::record('election_position_removed', $election, ['position' => $position->title]);

        return $this->back($election)->with('status', "{$position->title} removed.");
    }

    public function storeCandidate(Request $request, Election $election, ElectionPosition $position): RedirectResponse
    {
        Gate::authorize('update', $election);

        $bag = "candidate-{$position->id}";
        $data = $this->validatedCandidate($request, $election, $bag);
        $photo = $this->photo($request, $bag);

        $candidate = $position->candidates()->create([
            ...$data,
            'sort_order' => (int) $position->candidates()->max('sort_order') + 1,
        ]);

        if ($photo !== null) {
            $this->savePhoto($election, $candidate, $photo);
        }

        Audit::record('election_candidate_added', $election, ['position' => $position->title, 'candidate' => $candidate->name]);

        return $this->back($election, "position-{$position->id}")->with('status', "{$candidate->name} added to {$position->title}.");
    }

    public function updateCandidate(Request $request, Election $election, ElectionPosition $position, ElectionCandidate $candidate): RedirectResponse
    {
        Gate::authorize('update', $election);

        $bag = "candidate-edit-{$candidate->id}";
        $data = $this->validatedCandidate($request, $election, $bag, $candidate);
        $photo = $this->photo($request, $bag);

        $candidate->update($data);

        if ($photo !== null) {
            $this->savePhoto($election, $candidate, $photo);
        } elseif ($request->boolean('remove_photo') && $candidate->photo_path !== null) {
            Storage::disk('private')->delete($candidate->photo_path);
            $candidate->update(['photo_path' => null]);
        }

        return $this->back($election, "position-{$position->id}")->with('status', 'Candidate saved.');
    }

    public function destroyCandidate(Election $election, ElectionPosition $position, ElectionCandidate $candidate): RedirectResponse
    {
        Gate::authorize('update', $election);

        $candidate->delete();

        if ($candidate->photo_path !== null) {
            Storage::disk('private')->delete($candidate->photo_path);
        }

        Audit::record('election_candidate_removed', $election, ['position' => $position->title, 'candidate' => $candidate->name]);

        return $this->back($election, "position-{$position->id}")->with('status', "{$candidate->name} removed.");
    }

    /**
     * @return array{name: string, matric_number: string|null, manifesto: string|null, user_id: int|null}
     */
    private function validatedCandidate(Request $request, Election $election, string $bag, ?ElectionCandidate $candidate = null): array
    {
        $request->merge(['matric_number' => $request->filled('matric_number') ? MatricNumber::normalize($request->string('matric_number')->toString()) : null]);

        $data = $request->validateWithBag($bag, [
            'name' => ['required', 'string', 'max:150'],
            'matric_number' => ['nullable', 'string', 'max:32', 'regex:'.MatricNumber::PATTERN],
            'manifesto' => ['nullable', 'string', 'max:1000'],
        ], [
            'matric_number.regex' => 'Enter the matric number as printed on the ID card, for example F/ND/24/1234567.',
        ]);

        $matric = isset($data['matric_number']) ? (string) $data['matric_number'] : null;

        if ($matric !== null) {
            $positions = $election->positions()->pluck('title', 'id');
            $taken = ElectionCandidate::query()
                ->whereIn('position_id', $positions->keys())
                ->where('matric_number', $matric);

            if ($candidate !== null) {
                $taken->whereKeyNot($candidate->id);
            }

            $taken = $taken->first();

            if ($taken !== null) {
                throw ValidationException::withMessages([
                    'matric_number' => "{$taken->name} is already standing for {$positions->get($taken->position_id)} with this matric number.",
                ])->errorBag($bag);
            }
        }

        $userId = $matric !== null ? User::query()->where('matric_number', $matric)->value('id') : null;

        return [
            'name' => (string) $data['name'],
            'matric_number' => $matric,
            'manifesto' => isset($data['manifesto']) ? (string) $data['manifesto'] : null,
            // Linked to the student's account when there is one.
            'user_id' => $userId !== null ? (int) $userId : null,
        ];
    }

    /**
     * The chosen photo as a square JPEG, or null when none was chosen.
     */
    private function photo(Request $request, string $bag): ?string
    {
        $request->validateWithBag($bag, [
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('elections.photo_max_kb')],
        ], [
            'photo.mimes' => 'Choose a JPG, PNG or WebP photo.',
            'photo.max' => 'The photo must be '.intdiv((int) config('elections.photo_max_kb'), 1024).' MB or smaller.',
        ]);

        $file = $request->file('photo');

        if (! $file instanceof UploadedFile) {
            return null;
        }

        try {
            return Images::squareJpeg((string) file_get_contents($file->getRealPath()), (int) config('elections.photo_side'))['data'];
        } catch (UnreadableImage) {
            throw ValidationException::withMessages(['photo' => 'This photo couldn\'t be read. Try saving it as a JPG.'])->errorBag($bag);
        }
    }

    private function savePhoto(Election $election, ElectionCandidate $candidate, string $jpeg): void
    {
        $disk = Storage::disk('private');
        $old = $candidate->photo_path;

        // A new name each time, so browsers don't keep showing the old one.
        $path = "candidate-photos/{$election->id}/{$candidate->id}-".now()->format('YmdHis').'.jpg';
        $disk->put($path, $jpeg);
        $candidate->update(['photo_path' => $path]);

        if ($old !== null && $old !== $path) {
            $disk->delete($old);
        }
    }

    private function back(Election $election, ?string $anchor = null): RedirectResponse
    {
        return redirect()->to(route('elections.manage.show', $election).($anchor ? "#{$anchor}" : ''));
    }
}
