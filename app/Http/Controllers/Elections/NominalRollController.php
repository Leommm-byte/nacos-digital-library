<?php

namespace App\Http\Controllers\Elections;

use App\Enums\ElectionStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Election;
use App\Models\RollEntry;
use App\Rules\ValidMatricNumber;
use App\Support\Audit;
use App\Support\Classes\SchoolClass;
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
 * The nominal roll: the official list of current students, kept class by
 * class (programme, level and, for HND, the course: SWD or NCC). Admins upload each class's list as the
 * governor returns it (Excel or CSV); uploading a class replaces that class
 * only, or adds to it. Single students can be added or removed too.
 *
 * While voting is open in an election limited to the roll, the roll is
 * locked: nobody can be slipped onto it (or taken off it) to sway a vote.
 * Every change is in the audit log.
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
            ->withQueryString()
            // Next and previous land on the list, not the top of the page.
            ->fragment('find');

        $classes = [];
        $unsorted = 0;
        foreach (RollEntry::query()->toBase()->selectRaw('programme, level, arm, count(*) as total, max(updated_at) as updated')->groupBy('programme', 'level', 'arm')->get() as $row) {
            $class = SchoolClass::fromKey($row->programme.'|'.$row->level.'|'.$row->arm);

            if ($class === null) {
                // An earlier import without a class, or one the school no longer runs.
                $unsorted += (int) $row->total;
            } else {
                $classes[$class->key()] = ['total' => (int) $row->total, 'updated' => $row->updated];
            }
        }

        return view('elections.roll', [
            'entries' => $entries,
            'search' => $search,
            'classes' => $classes,
            'unsorted' => $unsorted,
            'total' => RollEntry::query()->count(),
            'withAccount' => RollEntry::query()->whereIn('matric_number', fn ($query) => $query->select('matric_number')->from('users'))->count(),
            'lastImport' => AuditLog::query()->where('action', 'roll_imported')->latest('id')->with('user:id,fullname')->first(),
            'lockedBy' => self::lockedBy(),
            'selected' => SchoolClass::fromKey($request->string('class')->toString()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (($locked = $this->locked()) !== null) {
            return $locked;
        }

        $data = $request->validate([
            'mode' => ['nullable', 'in:replace,add'],
            'class' => ['required', Rule::in(self::classKeys())],
            'roll' => ['required', 'file', 'max:5120', 'extensions:xlsx,csv,txt'],
        ], [
            'class.required' => 'Choose the class.',
            'class.in' => 'Choose the class.',
            'roll.required' => 'Choose the class list (Excel or CSV).',
            'roll.extensions' => 'Upload the Excel file (.xlsx) or a CSV.',
            'roll.max' => 'The file must be 5 MB or smaller.',
        ]);

        $schoolClass = SchoolClass::fromKey((string) $data['class']);
        if ($schoolClass === null) {
            abort(422);
        }
        $class = $schoolClass->label();

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

        // The class written at the top of the file must be the one chosen.
        if (($parsed['programme'] !== null && $parsed['programme'] !== $schoolClass->programme)
            || ($parsed['level'] !== null && $parsed['level'] !== $schoolClass->level)
            || ($parsed['arm'] !== null && $parsed['arm'] !== $schoolClass->arm)) {
            $fileLevel = $parsed['level'] ?? $schoolClass->level;
            $fileArm = $parsed['arm'] ?? ($fileLevel->stage() === $schoolClass->level->stage() ? $schoolClass->arm : null);
            $fileClass = (new SchoolClass($parsed['programme'] ?? $schoolClass->programme, $fileLevel, $fileArm))->label();

            return back()->withInput()->withErrors(['roll' => "This file is for {$fileClass}, but you chose {$class}. Check the class or the file."]);
        }

        $mismatches = RollImport::mismatches($parsed['rows'], $schoolClass);

        if ($mismatches !== [] && ! $request->boolean('confirm')) {
            return back()->withInput()
                ->withErrors(['roll' => count($mismatches).' of '.count($parsed['rows'])." matric numbers don't look like {$class} (programme letter, ND/HND or course digit). Wrong class or wrong file? If the list is right, tick \"Import anyway\" and upload it again."])
                ->with('mismatches', array_slice($mismatches, 0, 10));
        }

        $replace = $request->input('mode', 'replace') !== 'add';
        $result = RollImport::saveClass($parsed['rows'], $schoolClass, $replace);

        Audit::record('roll_imported', null, [
            'class' => $class,
            'mode' => $replace ? 'replace' : 'add',
            'programme' => $schoolClass->programme->value,
            'level' => $schoolClass->level->value,
            'arm' => $schoolClass->arm,
            'file' => $file->getClientOriginalName(),
            ...$result,
            'skipped' => count($parsed['skipped']),
            'mismatches' => count($mismatches),
        ]);

        return redirect()->route('roll.index')
            ->with('status', "{$class} imported.")
            ->with('import', [...$result, 'class' => $class, 'replace' => $replace, 'skipped' => array_slice($parsed['skipped'], 0, 20), 'skippedCount' => count($parsed['skipped'])]);
    }

    /**
     * Adds one student (a late registration, or someone the governor
     * missed), or moves them to this class if they're on the roll already.
     */
    public function storeOne(Request $request): RedirectResponse
    {
        if (($locked = $this->locked()) !== null) {
            return $locked;
        }

        $request->merge(['matric_number' => MatricNumber::normalize($request->string('matric_number')->toString())]);

        $data = $request->validateWithBag('student', [
            'matric_number' => ['required', 'string', 'max:32', new ValidMatricNumber],
            'fullname' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255'],
            'class' => ['required', Rule::in(self::classKeys())],
        ], [
            'class.required' => 'Choose the class.',
            'class.in' => 'Choose the class.',
        ]);

        $schoolClass = SchoolClass::fromKey((string) $data['class']);
        if ($schoolClass === null) {
            abort(422);
        }
        $class = $schoolClass->label();
        $matric = (string) $data['matric_number'];
        $row = [$matric => [
            'matric_number' => $matric,
            'fullname' => trim((string) preg_replace('/\s+/', ' ', (string) $data['fullname'])),
            'email' => isset($data['email']) ? strtolower((string) $data['email']) : null,
        ]];

        if (RollImport::mismatches($row, $schoolClass) !== [] && ! $request->boolean('confirm')) {
            return back()->withInput()->withErrors([
                'matric_number' => "{$matric} doesn't look like {$class} (programme letter, ND/HND or course digit). Check the class, or tick \"It's right\" and add again.",
            ], 'student')->with('confirmStudent', true);
        }

        $result = RollImport::saveClass($row, $schoolClass, false);
        Audit::record('roll_student_added', null, ['matric' => $matric, 'name' => $row[$matric]['fullname'], 'class' => $class, 'moved' => $result['moved'] > 0]);

        return redirect()->route('roll.index', ['q' => $matric])
            ->with('status', $result['moved'] > 0 ? "{$matric} moved to {$class}." : "{$matric} added to {$class}.");
    }

    /**
     * Takes one student off the roll (withdrawn, or added by mistake). Their
     * account, if any, stays; they just can't vote in roll-only elections.
     */
    public function destroy(Request $request, RollEntry $entry): RedirectResponse
    {
        if (($locked = $this->locked()) !== null) {
            return $locked;
        }

        $entry->delete();
        Audit::record('roll_student_removed', null, [
            'matric' => $entry->matric_number,
            'name' => $entry->fullname,
            'class' => $entry->classLabel(),
        ]);

        // Back to the same page of the list, where the student was.
        $back = route('roll.index', array_filter([
            'q' => $request->string('q')->toString(),
            'page' => max(1, $request->integer('page')) > 1 ? $request->integer('page') : null,
        ]));

        return redirect()->to($back.'#find')->with('status', "{$entry->matric_number} is off the roll.");
    }

    /**
     * @return list<string>
     */
    private static function classKeys(): array
    {
        return array_map(fn (SchoolClass $class) => $class->key(), SchoolClass::all());
    }

    /**
     * The open election that locks the roll, if any.
     */
    public static function lockedBy(): ?Election
    {
        return Election::query()->where('status', ElectionStatus::Open)->where('roll_only', true)->orderBy('ends_at')->first();
    }

    private function locked(): ?RedirectResponse
    {
        $election = self::lockedBy();

        return $election === null ? null : back()->withErrors([
            'roll' => "The roll is locked while voting is open in {$election->title}, so nobody can be added or removed to sway it. It unlocks when voting closes.",
        ]);
    }

    /**
     * The template with a class filled in, to send to its governor.
     */
    public function template(Request $request): BinaryFileResponse
    {
        $class = SchoolClass::fromKey($request->string('class')->toString());

        return response()
            ->download(RollTemplate::make($class?->programme, $class?->level, $class?->arm), RollTemplate::filename($class?->programme, $class?->level, $class?->arm), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }
}
