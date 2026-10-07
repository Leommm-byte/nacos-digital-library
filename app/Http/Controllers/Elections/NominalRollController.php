<?php

namespace App\Http\Controllers\Elections;

use App\Enums\ElectionStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Election;
use App\Models\RollEntry;
use App\Support\Audit;
use App\Support\Elections\RollImport;
use App\Support\MatricNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\View\View;

/**
 * The nominal roll: admins import the official list of current students,
 * and elections can be limited to it (see Election::ineligibilityReason).
 */
class NominalRollController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim($request->string('q')->toString());

        $entries = RollEntry::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('matric_number', 'like', '%'.MatricNumber::normalize($search).'%')
                        ->orWhere('fullname', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('matric_number')
            ->paginate(25)
            ->withQueryString();

        return view('elections.roll', [
            'entries' => $entries,
            'search' => $search,
            'total' => RollEntry::query()->count(),
            'withAccount' => RollEntry::query()->whereIn('matric_number', fn ($query) => $query->select('matric_number')->from('users'))->count(),
            'lastImport' => AuditLog::query()->where('action', 'roll_imported')->latest('id')->with('user:id,fullname')->first(),
            'votingOpen' => Election::query()->where('status', ElectionStatus::Open)->where('roll_only', true)->exists(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'roll' => ['required', 'file', 'max:4096', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel'],
            'mode' => ['required', 'in:replace,add'],
        ], [
            'roll.required' => 'Choose the CSV file with the nominal roll.',
            'roll.mimetypes' => 'Upload a CSV file. In Excel or Google Sheets, use "Save as" or "Download" and pick CSV.',
            'roll.max' => 'The file must be 4 MB or smaller.',
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('roll');
        $parsed = RollImport::parse((string) $file->getRealPath());

        if ($parsed['rows'] === []) {
            return back()->withErrors(['roll' => 'No matric numbers were found in this file. Check that it has a column of matric numbers such as F/ND/24/1234567.']);
        }

        $replace = $request->input('mode') === 'replace';
        $result = RollImport::save($parsed['rows'], $replace);

        Audit::record('roll_imported', null, [
            'mode' => $replace ? 'replace' : 'add',
            'file' => $file->getClientOriginalName(),
            'total' => $result['total'],
            'added' => $result['added'],
            'removed' => $result['removed'],
            'skipped' => count($parsed['skipped']),
        ]);

        return redirect()->route('roll.index')
            ->with('status', 'Nominal roll imported.')
            ->with('import', [...$result, 'skipped' => array_slice($parsed['skipped'], 0, 20), 'skippedCount' => count($parsed['skipped'])]);
    }
}
