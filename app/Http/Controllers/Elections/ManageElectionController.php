<?php

namespace App\Http\Controllers\Elections;

use App\Enums\ElectionStatus;
use App\Enums\Level;
use App\Http\Controllers\Controller;
use App\Models\Election;
use App\Models\RollEntry;
use App\Models\User;
use App\Support\Audit;
use App\Support\Elections\ElectionLifecycle;
use App\Support\Elections\LiveResults;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admins set up elections (details, who may vote, positions, candidates),
 * launch them for a set time and can stop them early. Several elections
 * can exist side by side; nothing is ever wiped to start a new one.
 */
class ManageElectionController extends Controller
{
    /** Matric entry years an election can be limited to start from. */
    public const FIRST_ENTRY_YEAR = 2019;

    public function index(): View
    {
        return view('elections.manage.index', [
            'elections' => Election::query()
                ->withCount(['positions', 'voters'])
                ->orderByRaw("case status when 'open' then 0 when 'draft' then 1 else 2 end")
                ->latest('id')
                ->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('elections.manage.form', [
            'election' => new Election(['title' => '']),
            'years' => $this->years(),
            'rollCount' => RollEntry::query()->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $election = new Election($this->validated($request));
        /** @var User $user */
        $user = $request->user();
        $election->created_by = $user->id;
        $election->save();

        Audit::record('election_created', $election, ['title' => $election->title]);

        return redirect()->route('elections.manage.show', $election)
            ->with('status', 'Election created. Add its positions and candidates next.');
    }

    public function show(Election $election): View
    {
        $election->load('positions.candidates');

        return view('elections.manage.show', [
            'election' => $election,
            'results' => LiveResults::read($election),
            'electorate' => $election->electorate()->count(),
            'rollCount' => RollEntry::query()->count(),
            'ballots' => $election->ballotCount(),
            'launchProblem' => $election->isDraft() ? ElectionLifecycle::launchProblem($election) : null,
        ]);
    }

    public function edit(Election $election): View
    {
        Gate::authorize('update', $election);

        return view('elections.manage.form', ['election' => $election, 'years' => $this->years(), 'rollCount' => RollEntry::query()->count()]);
    }

    public function update(Request $request, Election $election): RedirectResponse
    {
        Gate::authorize('update', $election);

        $election->update($this->validated($request));
        Audit::record('election_updated', $election, ['title' => $election->title]);

        return redirect()->route('elections.manage.show', $election)->with('status', 'Election details saved.');
    }

    public function destroy(Election $election): RedirectResponse
    {
        Gate::authorize('delete', $election);

        $election->delete();
        LiveResults::forget($election);
        Audit::record('election_deleted', $election, ['title' => $election->title]);

        return redirect()->route('elections.manage')->with('status', 'Draft election deleted.');
    }

    public function launch(Request $request, Election $election): RedirectResponse
    {
        Gate::authorize('update', $election);

        $data = $request->validate([
            'hours' => ['required', 'integer', Rule::in(Election::DURATIONS)],
        ]);

        $problem = ElectionLifecycle::launchProblem($election);

        if ($problem !== null) {
            return back()->withErrors(['launch' => $problem]);
        }

        ElectionLifecycle::launch($election, (int) $data['hours']);

        return redirect()->route('elections.manage.show', $election)->with('status', 'Voting is open.');
    }

    public function close(Election $election): RedirectResponse
    {
        abort_unless($election->status === ElectionStatus::Open, 409, 'This election isn\'t open.');

        ElectionLifecycle::close($election);

        return redirect()->route('elections.manage.show', $election)->with('status', 'Voting closed. The final results are published.');
    }

    /**
     * @return array{title: string, description: string|null, levels: list<string>|null, entry_year_from: int|null, entry_year_to: int|null, roll_only: bool}
     */
    private function validated(Request $request): array
    {
        $years = array_keys($this->years());

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'levels' => ['nullable', 'array'],
            'levels.*' => [Rule::enum(Level::class)],
            'entry_year_from' => ['nullable', 'integer', Rule::in($years)],
            'entry_year_to' => ['nullable', 'integer', Rule::in($years)],
        ]);

        if (isset($data['entry_year_from'], $data['entry_year_to']) && (int) $data['entry_year_to'] < (int) $data['entry_year_from']) {
            throw ValidationException::withMessages(['entry_year_to' => 'The last entry year can\'t be before the first.']);
        }

        /** @var list<string> $levels */
        $levels = array_values(array_unique((array) ($data['levels'] ?? [])));
        // Every level ticked is the same as no limit.
        $levels = count($levels) === count(Level::cases()) ? [] : $levels;

        return [
            'title' => (string) $data['title'],
            'description' => isset($data['description']) ? (string) $data['description'] : null,
            'levels' => $levels === [] ? null : $levels,
            'entry_year_from' => isset($data['entry_year_from']) ? (int) $data['entry_year_from'] : null,
            'entry_year_to' => isset($data['entry_year_to']) ? (int) $data['entry_year_to'] : null,
            'roll_only' => $request->boolean('roll_only'),
        ];
    }

    /**
     * @return array<int, string> year => label, e.g. 2024 => "2024 (…/24/…)"
     */
    private function years(): array
    {
        $years = [];

        for ($year = (int) now()->year + 1; $year >= self::FIRST_ENTRY_YEAR; $year--) {
            $years[$year] = sprintf('%d (…/%02d/…)', $year, $year % 100);
        }

        return $years;
    }
}
