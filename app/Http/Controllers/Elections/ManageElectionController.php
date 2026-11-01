<?php

namespace App\Http\Controllers\Elections;

use App\Enums\ElectionStatus;
use App\Enums\Level;
use App\Enums\Programme;
use App\Http\Controllers\Controller;
use App\Models\Election;
use App\Models\User;
use App\Support\Audit;
use App\Support\Classes\Arms;
use App\Support\Elections\ElectionDesk;
use App\Support\Elections\ElectionLifecycle;
use App\Support\Elections\LiveResults;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admins set up elections (details, who may vote, positions, candidates),
 * launch them for a set time and can stop them early. Several elections
 * can exist side by side; nothing is ever wiped to start a new one.
 *
 * Departments run their own elections with the same screens
 * (App\Http\Controllers\Vote\Admin\ElectionController): desk() says
 * which elections, routes and layout.
 */
class ManageElectionController extends Controller
{
    /** Matric entry years an election can be limited to start from. */
    public const FIRST_ENTRY_YEAR = 2019;

    protected function desk(): ElectionDesk
    {
        return ElectionDesk::nacos();
    }

    /**
     * Only drafts change (ElectionPolicy).
     */
    protected function authorizeChange(string $ability, Election $election): void
    {
        Gate::authorize($ability, $election);
    }

    public function index(): View
    {
        $desk = $this->desk();

        return view('elections.manage.index', [
            'desk' => $desk,
            'elections' => $desk->elections()
                ->withCount(['positions', $desk->isDepartment() ? 'departmentVoters as voters_count' : 'voters'])
                ->orderByRaw("case status when 'open' then 0 when 'draft' then 1 else 2 end")
                ->latest('id')
                ->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('elections.manage.form', [
            'desk' => $this->desk(),
            'election' => new Election(['title' => '']),
            'years' => $this->years(),
            'rollCount' => $this->desk()->rollCount(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $election = new Election($this->validated($request));
        $desk = $this->desk();
        $election->organization_id = $desk->organization?->id;
        // A department's admins aren't NACOS users.
        $user = $request->user();
        $election->created_by = ! $desk->isDepartment() && $user instanceof User ? $user->id : null;
        $election->save();

        if (! $desk->isDepartment()) {
            Audit::record('election_created', $election, ['title' => $election->title]);
        }

        return redirect()->to($desk->route('.show', $election))
            ->with('status', 'Election created. Add its positions and candidates next.');
    }

    public function show(Election $election): View
    {
        $election->load('positions.candidates');

        return view('elections.manage.show', [
            'desk' => $this->desk(),
            'election' => $election,
            'results' => LiveResults::read($election),
            'electorate' => $election->electorate()->count(),
            'rollCount' => $this->desk()->rollCount(),
            'ballots' => $election->ballotCount(),
            'launchProblem' => $election->isDraft() ? ElectionLifecycle::launchProblem($election) : null,
        ]);
    }

    public function edit(Election $election): View
    {
        $this->authorizeChange('update', $election);

        return view('elections.manage.form', ['desk' => $this->desk(), 'election' => $election, 'years' => $this->years(), 'rollCount' => $this->desk()->rollCount()]);
    }

    public function update(Request $request, Election $election): RedirectResponse
    {
        $this->authorizeChange('update', $election);

        $election->update($this->validated($request));
        if (! $election->isForDepartment()) {
            Audit::record('election_updated', $election, ['title' => $election->title]);
        }

        return redirect()->to($this->desk()->route('.show', $election))->with('status', 'Election details saved.');
    }

    public function destroy(Election $election): RedirectResponse
    {
        $this->authorizeChange('delete', $election);

        $election->delete();
        LiveResults::forget($election);
        Storage::disk('private')->deleteDirectory("candidate-photos/{$election->id}");
        if (! $election->isForDepartment()) {
            Audit::record('election_deleted', $election, ['title' => $election->title]);
        }

        return redirect()->to($this->desk()->route(''))->with('status', 'Draft election deleted.');
    }

    public function launch(Request $request, Election $election): RedirectResponse
    {
        $this->authorizeChange('update', $election);

        $data = $request->validate([
            'hours' => ['required', 'integer', Rule::in(Election::DURATIONS)],
        ]);

        $problem = ElectionLifecycle::launchProblem($election);

        if ($problem !== null) {
            return back()->withErrors(['launch' => $problem]);
        }

        ElectionLifecycle::launch($election, (int) $data['hours']);

        return redirect()->to($this->desk()->route('.show', $election))->with('status', 'Voting is open.');
    }

    public function close(Election $election): RedirectResponse
    {
        abort_unless($election->status === ElectionStatus::Open, 409, 'This election isn\'t open.');

        ElectionLifecycle::close($election);

        return redirect()->to($this->desk()->route('.show', $election))->with('status', 'Voting closed. The final results are published.');
    }

    /**
     * The results as a spreadsheet (CSV): the totals, then every candidate's
     * votes. Live standings while voting is open, final once closed.
     */
    public function resultsCsv(Election $election): StreamedResponse
    {
        $results = $this->results($election);
        $zone = (string) config('app.display_timezone');
        $filename = Str::slug($election->title).'-results.csv';

        return response()->streamDownload(function () use ($election, $results, $zone) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\u{FEFF}"); // Excel reads the file as UTF-8.
            fputcsv($out, [$election->title], escape: '');
            fputcsv($out, [($results['final'] ?? false) ? 'Final results' : 'Live standings (voting still open)', 'Closed', ($election->closed_at ?? $election->ends_at)?->timezone($zone)->format('j M Y, g:i a') ?? ''], escape: '');
            fputcsv($out, ['Ballots', $results['ballots'] ?? 0, 'Could vote', $results['electorate'] ?? 0, 'Turnout %', $results['turnout'] ?? 0], escape: '');
            fputcsv($out, [], escape: '');
            fputcsv($out, ['Position', 'Candidate', 'Votes', 'Percent of the position\'s votes', 'Result'], escape: '');
            foreach ($results['positions'] ?? [] as $position) {
                foreach ($position['candidates'] as $candidate) {
                    $result = $candidate['tied'] ? 'Tied' : ($candidate['leading'] ? (($results['final'] ?? false) ? 'Winner' : 'Leading') : '');
                    fputcsv($out, [$position['title'], $candidate['name'], $candidate['votes'], $candidate['percent'], $result], escape: '');
                }
                fputcsv($out, [$position['title'], 'No vote', $position['skipped'], '', ''], escape: '');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    /**
     * The results on one page to print or save as PDF.
     */
    public function resultsPrint(Election $election): View
    {
        return view('elections.manage.results-print', [
            'desk' => $this->desk(),
            'election' => $election,
            'results' => $this->results($election),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function results(Election $election): array
    {
        abort_if($election->isDraft(), 404);

        return LiveResults::read($election) ?? LiveResults::build($election);
    }

    /**
     * @return array{title: string, description: string|null, levels: list<string>|null, programmes: list<string>|null, arms: list<string>|null, entry_year_from: int|null, entry_year_to: int|null, roll_only: bool, allow_late_accounts: bool}
     */
    private function validated(Request $request): array
    {
        $years = array_keys($this->years());

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'levels' => ['nullable', 'array'],
            'levels.*' => [Rule::enum(Level::class)],
            'programmes' => ['nullable', 'array'],
            'programmes.*' => [Rule::enum(Programme::class)],
            'arms' => ['nullable', 'array'],
            'arms.*' => [Rule::in(array_keys(Arms::all()))],
            'entry_year_from' => ['nullable', 'integer', Rule::in($years)],
            'entry_year_to' => ['nullable', 'integer', Rule::in($years)],
        ]);

        if (isset($data['entry_year_from'], $data['entry_year_to']) && (int) $data['entry_year_to'] < (int) $data['entry_year_from']) {
            throw ValidationException::withMessages(['entry_year_to' => 'The last entry year can\'t be before the first.']);
        }

        /** @var list<string> $programmes */
        $programmes = array_values(array_unique((array) ($data['programmes'] ?? [])));
        $programmes = count($programmes) === count(Programme::cases()) ? [] : $programmes;

        /** @var list<string> $levels */
        $levels = array_values(array_unique((array) ($data['levels'] ?? [])));
        // Every level ticked is the same as no limit.
        $levels = count($levels) === count(Level::cases()) ? [] : $levels;

        /** @var list<string> $arms */
        $arms = array_values(array_unique((array) ($data['arms'] ?? [])));
        // Departments have no courses (arms), and their elections are always
        // for students on their roll: registering needs a place on it.
        $department = $this->desk()->isDepartment();
        $arms = $department ? [] : $arms;

        return [
            'title' => (string) $data['title'],
            'description' => isset($data['description']) ? (string) $data['description'] : null,
            'levels' => $levels === [] ? null : $levels,
            'programmes' => $programmes === [] ? null : $programmes,
            'arms' => $arms === [] ? null : $arms,
            'entry_year_from' => isset($data['entry_year_from']) ? (int) $data['entry_year_from'] : null,
            'entry_year_to' => isset($data['entry_year_to']) ? (int) $data['entry_year_to'] : null,
            'roll_only' => $department || $request->boolean('roll_only'),
            'allow_late_accounts' => $request->boolean('allow_late_accounts'),
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
