<?php

namespace App\Http\Controllers\Timetables;

use App\Enums\Level;
use App\Enums\Programme;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\User;
use App\Support\Audit;
use App\Support\Classes\Arms;
use App\Support\Sessions\ProgrammeSessions;
use App\Support\Spreadsheets\SpreadsheetRows;
use App\Support\Timetables\ExamImport;
use App\Support\Timetables\TimetableTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Exam timetables, one per programme: full-time, part-time and CODFEL
 * write exams at different times. Admins keep each one and show it to its
 * students when it's confirmed (it can be prepared unseen). Students see
 * their programme's, their own papers first (by level and course), and can
 * show everyone's in the programme.
 */
class ExamController extends Controller
{
    /** A programme's exam timetable has room for a whole session's papers. */
    public const MAX = 500;

    /**
     * Whether a programme's students can see its exam timetable.
     */
    public static function published(Programme $programme): bool
    {
        return ProgrammeSessions::examsPublished($programme);
    }

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $admin = Gate::allows('manage-exams');
        $programme = $admin ? (Programme::tryFrom($request->string('programme')->toString()) ?? $user->programme) : $user->programme;

        if (! $admin && ($user->isGraduated() || ! self::published($programme))) {
            return view('exams.index', ['hidden' => true, 'graduated' => $user->isGraduated()]);
        }

        $today = now()->timezone((string) config('app.display_timezone'))->startOfDay();
        $past = $request->boolean('past');

        $exams = Exam::current($programme)
            ->when(! $past, fn ($query) => $query->whereDate('date', '>=', $today->toDateString()))
            ->get();
        $mine = $exams->filter(fn (Exam $exam) => $exam->isFor($user));
        // Your own papers first; everyone's when you have none listed.
        $show = match ($request->string('show')->toString()) {
            'all' => 'all',
            'mine' => 'mine',
            default => $mine->isEmpty() && $exams->isNotEmpty() ? 'all' : 'mine',
        };

        return view('exams.index', [
            'hidden' => false,
            'programme' => $programme,
            'programmes' => $admin ? Programme::cases() : [],
            'published' => self::published($programme),
            'days' => ($show === 'all' ? $exams : $mine)->groupBy(fn (Exam $exam) => $exam->date->toDateString()),
            'show' => $show,
            'past' => $past,
            'counts' => ['mine' => $mine->count(), 'all' => $exams->count()],
            'mineIds' => $mine->pluck('id')->all(),
            'today' => $today,
            'hasPast' => ! $past && Exam::current($programme)->whereDate('date', '<', $today->toDateString())->exists(),
        ]);
    }

    public function manage(Request $request): View
    {
        $programme = Programme::tryFrom($request->string('programme')->toString()) ?? Programme::FullTime;
        $exams = Exam::current($programme)->get();
        $counts = Exam::query()->whereNull('archived_at')->toBase()->selectRaw('programme, count(*) as total')->groupBy('programme')->pluck('total', 'programme');

        return view('exams.manage', [
            'programme' => $programme,
            'tabs' => array_map(fn (Programme $p) => [
                'programme' => $p,
                'count' => (int) ($counts[$p->value] ?? 0),
                'published' => self::published($p),
            ], Programme::cases()),
            'published' => self::published($programme),
            'days' => $exams->groupBy(fn (Exam $exam) => $exam->date->toDateString()),
            'count' => $exams->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $programme = $this->programme($request);
        $data = $this->validated($request, 'add');

        if (Exam::current($programme)->count() >= self::MAX) {
            return back()->withInput()->withErrors(['course_code' => 'An exam timetable can hold '.self::MAX.' papers.'], 'add');
        }

        $exam = Exam::create([...$data, 'programme' => $programme]);
        Audit::record('exam_added', $exam, ['programme' => $programme->label(), 'course' => $exam->course_code, 'date' => $exam->date->toDateString()]);

        return redirect()->to(route('exams.manage', ['programme' => $programme->value]).'#date-'.$exam->date->toDateString())
            ->with('status', "{$exam->course_code} added on {$exam->date->format('D j M')}.");
    }

    public function update(Request $request, Exam $exam): RedirectResponse
    {
        abort_if($exam->archived_at !== null, 404);

        $exam->update($this->validated($request, 'exam-'.$exam->id));
        Audit::record('exam_updated', $exam, ['programme' => $exam->programme->label(), 'course' => $exam->course_code, 'date' => $exam->date->toDateString()]);

        return redirect()->to(route('exams.manage', ['programme' => $exam->programme->value]).'#date-'.$exam->date->toDateString())
            ->with('status', "{$exam->course_code} saved.");
    }

    public function destroy(Exam $exam): RedirectResponse
    {
        abort_if($exam->archived_at !== null, 404);

        $exam->delete();
        Audit::record('exam_removed', $exam, ['programme' => $exam->programme->label(), 'course' => $exam->course_code, 'date' => $exam->date->toDateString()]);

        return redirect()->route('exams.manage', ['programme' => $exam->programme->value])
            ->with('status', "{$exam->course_code} removed from the {$exam->programme->label()} exam timetable.");
    }

    /**
     * Shows a programme's exam timetable to its students, or hides it again
     * while it's being prepared.
     */
    public function publish(Request $request): RedirectResponse
    {
        $programme = $this->programme($request);
        $published = $request->boolean('published');
        ProgrammeSessions::setExamsPublished($programme, $published);
        Audit::record($published ? 'exams_published' : 'exams_hidden', null, ['programme' => $programme->label()]);

        return redirect()->route('exams.manage', ['programme' => $programme->value])->with('status', $published
            ? "The {$programme->label()} exam timetable is now visible to its students."
            : "The {$programme->label()} exam timetable is hidden from students.");
    }

    /**
     * Replaces a programme's exam timetable with a spreadsheet's.
     */
    public function import(Request $request): RedirectResponse
    {
        $programme = $this->programme($request);
        $request->validateWithBag('import', [
            'exams' => ['required', 'file', 'max:2048', 'extensions:xlsx,csv,txt'],
        ], [
            'exams.required' => 'Choose the exam timetable file (Excel or CSV).',
            'exams.extensions' => 'Upload the Excel file (.xlsx) or a CSV.',
            'exams.max' => 'The file must be 2 MB or smaller.',
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('exams');

        try {
            $parsed = ExamImport::parse(SpreadsheetRows::read((string) $file->getRealPath(), $file->getClientOriginalExtension()), $programme);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['exams' => $exception->getMessage().' Save it from Excel as .xlsx or CSV and try again.'], 'import');
        }

        if (! $parsed['headings']) {
            return back()->withErrors(['exams' => 'The headings weren\'t found. Use the template: a heading row with Date, Start and Course code, then one paper per row.'], 'import');
        }

        $rows = $parsed['exams'];
        $skipped = $parsed['skipped'];

        if ($rows === []) {
            return back()->withErrors(['exams' => 'No '.$programme->label().' papers were found in this file. Each row needs a date, a start time and a course code.'], 'import')
                ->with('skipped', array_slice($skipped, 0, 10));
        }

        if (count($rows) > self::MAX) {
            return back()->withErrors(['exams' => 'An exam timetable can hold '.self::MAX.' papers; this file has '.count($rows).'.'], 'import');
        }

        DB::transaction(function () use ($rows, $programme) {
            Exam::query()->whereNull('archived_at')->where('programme', $programme)->delete();

            foreach ($rows as $row) {
                Exam::create([...$row, 'programme' => $programme]);
            }
        });

        Audit::record('exams_imported', null, ['programme' => $programme->label(), 'file' => $file->getClientOriginalName(), 'papers' => count($rows), 'skipped' => count($skipped)]);

        return redirect()->route('exams.manage', ['programme' => $programme->value])
            ->with('status', "{$programme->label()} exam timetable uploaded.")
            ->with('import', ['count' => count($rows), 'skipped' => array_slice($skipped, 0, 20), 'skippedCount' => count($skipped)]);
    }

    public function template(): BinaryFileResponse
    {
        return response()
            ->download(TimetableTemplate::exams(), TimetableTemplate::filename('exam-timetable'), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    /**
     * The programme a form was sent for.
     */
    private function programme(Request $request): Programme
    {
        $request->validate(['programme' => ['required', Rule::enum(Programme::class)]]);

        return Programme::from($request->string('programme')->toString());
    }

    /**
     * @return array{date: string, starts_at: string, ends_at: string|null, course_code: string, course_title: string|null, venue: string|null, levels: list<string>|null, arms: list<string>|null, note: string|null}
     */
    private function validated(Request $request, string $bag): array
    {
        $data = $request->validateWithBag($bag, [
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:2100-01-01'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['nullable', 'date_format:H:i', 'after:starts_at'],
            'course_code' => ['required', 'string', 'max:20'],
            'course_title' => ['nullable', 'string', 'max:150'],
            'venue' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:200'],
            'levels' => ['nullable', 'array'],
            'levels.*' => [Rule::enum(Level::class)],
            'arms' => ['nullable', 'array'],
            'arms.*' => [Rule::in(array_keys(Arms::all()))],
        ], [
            'date.required' => 'Choose the date.',
            'ends_at.after' => 'The paper must end after it starts.',
            'course_code.required' => 'Enter the course code, for example CSC 201.',
        ]);

        $text = fn (string $key) => isset($data[$key]) && trim((string) $data[$key]) !== '' ? trim((string) preg_replace('/\s+/', ' ', (string) $data[$key])) : null;

        return [
            'date' => (string) $data['date'],
            'starts_at' => (string) $data['starts_at'],
            'ends_at' => $text('ends_at'),
            'course_code' => strtoupper((string) $text('course_code')),
            'course_title' => $text('course_title'),
            'venue' => $text('venue'),
            'levels' => self::list($data['levels'] ?? null),
            'arms' => self::list($data['arms'] ?? null),
            'note' => $text('note'),
        ];
    }

    /**
     * @return list<string>|null
     */
    private static function list(mixed $values): ?array
    {
        $values = array_values(array_unique(array_map(fn ($value) => (string) $value, (array) $values)));

        return $values === [] ? null : $values;
    }
}
