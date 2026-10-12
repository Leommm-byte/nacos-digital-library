<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ElectionStatus;
use App\Http\Controllers\Controller;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\Executive;
use App\Support\Audit;
use App\Support\Elections\LiveResults;
use App\Support\Uploads\Images;
use App\Support\Uploads\UnreadableImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admins keep "Know your executives": add each executive with their
 * position and photo, put them in order, or fill the list from a closed
 * election's winners.
 */
class ExecutiveController extends Controller
{
    /** A whole executive council, with room to spare. */
    public const MAX = 40;

    public function index(): View
    {
        return view('admin.executives.index', [
            'executives' => Executive::query()->orderBy('sort_order')->orderBy('id')->get(),
            'elections' => Election::query()->where('status', ElectionStatus::Closed)->latest('closed_at')->limit(10)->get(['id', 'title', 'closed_at']),
            'max' => self::MAX,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, 'add');
        $photo = $this->photo($request, 'add');

        if (Executive::query()->count() >= self::MAX) {
            return back()->withInput()->withErrors(['name' => 'The list can hold '.self::MAX.' executives.'], 'add');
        }

        $executive = Executive::create([...$data, 'sort_order' => (int) Executive::query()->max('sort_order') + 1]);
        if ($photo !== null) {
            $this->savePhoto($executive, $photo);
        }

        Audit::record('executive_added', $executive, ['name' => $executive->name, 'position' => $executive->position]);

        return redirect()->to(route('admin.executives.index').'#executive-'.$executive->id)->with('status', "{$executive->name} added.");
    }

    public function update(Request $request, Executive $executive): RedirectResponse
    {
        $bag = 'executive-'.$executive->id;
        $executive->update($this->validated($request, $bag));
        $photo = $this->photo($request, $bag);

        if ($photo !== null) {
            $this->savePhoto($executive, $photo);
        } elseif ($request->boolean('remove_photo') && $executive->photo_path !== null) {
            Storage::disk('private')->delete($executive->photo_path);
            $executive->forceFill(['photo_path' => null])->save();
        }

        Audit::record('executive_updated', $executive, ['name' => $executive->name, 'position' => $executive->position]);

        return redirect()->to(route('admin.executives.index').'#executive-'.$executive->id)->with('status', "{$executive->name} saved.");
    }

    public function destroy(Executive $executive): RedirectResponse
    {
        if ($executive->photo_path !== null) {
            Storage::disk('private')->delete($executive->photo_path);
        }
        $executive->delete();

        Audit::record('executive_removed', $executive, ['name' => $executive->name, 'position' => $executive->position]);

        return redirect()->route('admin.executives.index')->with('status', "{$executive->name} removed.");
    }

    /**
     * Moves an executive one place up or down the list.
     */
    public function move(Request $request, Executive $executive): RedirectResponse
    {
        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];
        $ids = Executive::query()->orderBy('sort_order')->orderBy('id')->pluck('id')->all();
        $index = array_search($executive->id, $ids, true);
        $swap = $direction === 'up' ? $index - 1 : $index + 1;

        if ($index !== false && isset($ids[$swap])) {
            [$ids[$index], $ids[$swap]] = [$ids[$swap], $ids[$index]];
            DB::transaction(function () use ($ids) {
                foreach ($ids as $order => $id) {
                    Executive::query()->whereKey($id)->update(['sort_order' => $order]);
                }
            });
        }

        return redirect()->to(route('admin.executives.index').'#executive-'.$executive->id);
    }

    /**
     * Replaces the list with a closed election's winners, one per position
     * in ballot order, with their photos. A tied position is left out for
     * the admin to add by hand.
     */
    public function fromElection(Election $election): RedirectResponse
    {
        abort_unless($election->status === ElectionStatus::Closed, 404);

        $results = LiveResults::read($election) ?? ['positions' => []];
        $winners = [];
        $tied = [];

        // The results file is decoded JSON: read what's there, defensively.
        foreach ((array) ($results['positions'] ?? []) as $position) {
            if (! is_array($position)) {
                continue;
            }
            $top = array_values(array_filter((array) ($position['candidates'] ?? []), fn ($candidate) => is_array($candidate) && ($candidate['leading'] ?? false) === true));
            $title = (string) ($position['title'] ?? '');

            if (count($top) === 1) {
                $winners[] = ['position' => $title, 'candidate' => (int) $top[0]['id']];
            } elseif (count($top) > 1) {
                $tied[] = $title;
            }
        }

        if ($winners === []) {
            return back()->withErrors(['election' => 'That election has no clear winners to add.'], 'fill');
        }

        $candidates = ElectionCandidate::query()->whereIn('id', array_column($winners, 'candidate'))->get()->keyBy('id');
        $disk = Storage::disk('private');
        $old = Executive::query()->whereNotNull('photo_path')->pluck('photo_path')->all();

        DB::transaction(function () use ($winners, $candidates, $disk) {
            Executive::query()->delete();

            foreach ($winners as $order => $winner) {
                /** @var ElectionCandidate $candidate */
                $candidate = $candidates[$winner['candidate']];
                $executive = Executive::create(['name' => $candidate->name, 'position' => $winner['position'], 'sort_order' => $order]);

                if ($candidate->photo_path !== null && $disk->exists($candidate->photo_path)) {
                    $this->savePhoto($executive, (string) $disk->get($candidate->photo_path));
                }
            }
        });

        $disk->delete($old);
        Audit::record('executives_filled', $election, ['title' => $election->title, 'executives' => count($winners)]);

        $message = count($winners).' executives added from '.$election->title.'.';
        if ($tied !== []) {
            $message .= ' Tied, so add by hand: '.implode(', ', $tied).'.';
        }

        return redirect()->route('admin.executives.index')->with('status', $message);
    }

    /**
     * @return array{name: string, position: string, details: string|null}
     */
    private function validated(Request $request, string $bag): array
    {
        $data = $request->validateWithBag($bag, [
            'name' => ['required', 'string', 'max:150'],
            'position' => ['required', 'string', 'max:100'],
            'details' => ['nullable', 'string', 'max:200'],
        ], [
            'name.required' => 'Enter their name.',
            'position.required' => 'Enter their position, for example President.',
        ]);

        $text = fn (?string $value) => $value !== null && trim($value) !== '' ? trim((string) preg_replace('/\s+/', ' ', $value)) : null;

        return [
            'name' => (string) $text($data['name']),
            'position' => (string) $text($data['position']),
            'details' => $text($data['details'] ?? null),
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

    private function savePhoto(Executive $executive, string $jpeg): void
    {
        $disk = Storage::disk('private');
        $old = $executive->photo_path;

        // A new name each time, so browsers don't keep showing the old one.
        $path = "executive-photos/{$executive->id}-".now()->format('YmdHis').bin2hex(random_bytes(2)).'.jpg';
        $disk->put($path, $jpeg);
        $executive->forceFill(['photo_path' => $path])->save();

        if ($old !== null && $old !== $path) {
            $disk->delete($old);
        }
    }
}
