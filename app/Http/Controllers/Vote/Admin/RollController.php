<?php

namespace App\Http\Controllers\Vote\Admin;

use App\Enums\Level;
use App\Enums\Programme;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Rules\ValidMatricNumber;
use App\Support\Classes\SchoolClass;
use App\Support\Departments\DepartmentRoll;
use App\Support\Elections\RollImport;
use App\Support\Elections\RollTemplate;
use App\Support\MatricNumber;
use App\Support\Spreadsheets\SpreadsheetRows;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A department's nominal roll: only students on it can register and vote.
 * Uploaded class by class from the same Excel template as the NACOS roll
 * (RollImport), or one student at a time. Locked while voting is open.
 */
class RollController extends Controller
{
    public function index(Request $request, Organization $organization): View
    {
        $search = trim($request->string('q')->toString());

        $entries = $organization->roll()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('matric_number', 'like', '%'.MatricNumber::normalize($search).'%')
                        ->orWhere('fullname', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('matric_number')
            ->paginate(25)
            ->withQueryString()
            ->fragment('find');

        $classes = $organization->roll()->toBase()
            ->selectRaw('programme, level, count(*) as total')
            ->groupBy('programme', 'level')
            ->get()
            ->map(fn ($row) => [
                'label' => trim((Level::tryFrom((string) $row->level)?->label() ?? 'No level').' '.(Programme::tryFrom((string) $row->programme)?->label() ?? '')),
                'total' => (int) $row->total,
            ])
            ->sortBy('label')
            ->values()
            ->all();

        return view('vote.admin.roll', [
            'entries' => $entries,
            'search' => $search,
            'classes' => $classes,
            'total' => $organization->roll()->count(),
            'registered' => $organization->roll()->whereIn('matric_number', $organization->voters()->whereNotNull('matric_number')->select('matric_number'))->count(),
            'lockedBy' => DepartmentRoll::lockedBy($organization),
        ]);
    }

    public function store(Request $request, Organization $organization): RedirectResponse
    {
        if (($locked = $this->locked($organization)) !== null) {
            return $locked;
        }

        $data = $request->validate([
            'mode' => ['nullable', 'in:replace,add'],
            'programme' => ['required', Rule::enum(Programme::class)],
            'level' => ['required', Rule::enum(Level::class)],
            'roll' => ['required', 'file', 'max:5120', 'extensions:xlsx,csv,txt'],
        ], [
            'programme.required' => 'Choose the programme.',
            'level.required' => 'Choose the level.',
            'roll.required' => 'Choose the class list (Excel or CSV).',
            'roll.extensions' => 'Upload the Excel file (.xlsx) or a CSV.',
            'roll.max' => 'The file must be 5 MB or smaller.',
        ]);

        $programme = Programme::from((string) $data['programme']);
        $level = Level::from((string) $data['level']);
        $class = "{$level->label()} {$programme->label()}";

        /** @var UploadedFile $file */
        $file = $request->file('roll');

        try {
            $parsed = RollImport::parse(SpreadsheetRows::read((string) $file->getRealPath(), $file->getClientOriginalExtension()));
        } catch (RuntimeException $exception) {
            return back()->withInput()->withErrors(['roll' => $exception->getMessage().' Save it from Excel as .xlsx or CSV and try again.']);
        }

        if ($parsed['rows'] === []) {
            return back()->withInput()->withErrors(['roll' => 'No matric numbers were found in this file. Check that it has a column of matric numbers such as F/ND/24/1234567.']);
        }

        if (($parsed['programme'] !== null && $parsed['programme'] !== $programme) || ($parsed['level'] !== null && $parsed['level'] !== $level)) {
            $fileClass = trim(($parsed['level'] ?? $level)->label().' '.($parsed['programme'] ?? $programme)->label());

            return back()->withInput()->withErrors(['roll' => "This file is for {$fileClass}, but you chose {$class}. Check the class or the file."]);
        }

        $mismatches = RollImport::mismatches($parsed['rows'], new SchoolClass($programme, $level));

        if ($mismatches !== [] && ! $request->boolean('confirm')) {
            return back()->withInput()
                ->withErrors(['roll' => count($mismatches).' of '.count($parsed['rows'])." matric numbers don't look like {$class} (programme letter, or ND/HND). Wrong class or wrong file? If the list is right, tick \"Import anyway\" and upload it again."])
                ->with('mismatches', array_slice($mismatches, 0, 10));
        }

        $replace = $request->input('mode', 'replace') !== 'add';
        $result = DepartmentRoll::saveClass($organization, $parsed['rows'], $programme, $level, $replace);

        $summary = "{$class}: {$result['total']} on the list";
        $summary .= $result['removed'] > 0 ? ", {$result['removed']} removed" : '';
        $summary .= count($parsed['skipped']) > 0 ? ', '.count($parsed['skipped']).' rows skipped (no matric number)' : '';

        return redirect()->route('vote.admin.roll')->with('status', $summary.'.');
    }

    /**
     * Adds one student, or moves them to this class.
     */
    public function storeOne(Request $request, Organization $organization): RedirectResponse
    {
        if (($locked = $this->locked($organization)) !== null) {
            return $locked;
        }

        $request->merge(['matric_number' => MatricNumber::normalize($request->string('matric_number')->toString())]);

        $data = $request->validateWithBag('student', [
            'matric_number' => ['required', 'string', 'max:32', new ValidMatricNumber],
            'fullname' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255'],
            'programme' => ['required', Rule::enum(Programme::class)],
            'level' => ['required', Rule::enum(Level::class)],
        ]);

        $matric = (string) $data['matric_number'];
        $result = DepartmentRoll::saveClass($organization, [$matric => [
            'matric_number' => $matric,
            'fullname' => trim((string) preg_replace('/\s+/', ' ', (string) $data['fullname'])),
            'email' => isset($data['email']) ? strtolower((string) $data['email']) : null,
        ]], Programme::from((string) $data['programme']), Level::from((string) $data['level']), false);

        return redirect()->route('vote.admin.roll', ['q' => $matric])
            ->with('status', $result['moved'] > 0 ? "{$matric} moved to its new class." : "{$matric} added to the roll.");
    }

    public function destroy(Organization $organization, int $entry): RedirectResponse
    {
        if (($locked = $this->locked($organization)) !== null) {
            return $locked;
        }

        $row = $organization->roll()->findOrFail($entry);
        $row->delete();

        return back()->with('status', "{$row->matric_number} removed from the roll.");
    }

    /**
     * The Excel template, with the class filled in when one is chosen.
     */
    public function template(Request $request): BinaryFileResponse
    {
        $programme = Programme::tryFrom($request->string('programme')->toString());
        $level = Level::tryFrom($request->string('level')->toString());

        return response()
            ->download(RollTemplate::make($programme, $level, courses: false), RollTemplate::filename($programme, $level), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    private function locked(Organization $organization): ?RedirectResponse
    {
        $election = DepartmentRoll::lockedBy($organization);

        return $election === null ? null : back()->withErrors([
            'roll' => "The roll is locked while voting is open in {$election->title}, so nobody can be added or removed to sway it. It unlocks when voting closes.",
        ]);
    }
}
