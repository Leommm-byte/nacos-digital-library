<?php

namespace App\Http\Controllers\Timetables;

use App\Enums\Level;
use App\Enums\Programme;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\User;
use App\Support\Audit;
use App\Support\Classes\Arms;
use App\Support\Spreadsheets\SpreadsheetRows;
use App\Support\Timetables\ExamImport;
use App\Support\Timetables\TimetableTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The exam timetable: kept by admins, seen by everyone signed in. Students
 * see their own papers first (by level, programme and course), and can show
 * everyone's.
 */
class ExamController extends Controller
{
    /** The exam timetable has room for a whole session's papers. */
    public const MAX = 500;

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $today = now()->timezone((string) config('app.display_timezone'))->startOfDay();
        $past = $request->boolean('past');

        $exams = Exam::current()
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
            'days' => ($show === 'all' ? $exams : $mine)->groupBy(fn (Exam $exam) => $exam->date->toDateString()),
            'show' => $show,
            'past' => $past,
            'counts' => ['mine' => $mine->count(), 'all' => $exams->count()],
            'mineIds' => $mine->pluck('id')->all(),
            'today' => $today,
            'hasPast' => ! $past && Exam::current()->whereDate('date', '<', $today->toDateString())->exists(),
        ]);
    }

    public function manage(): View
    {
        $exams = Exam::current()->get();

        return view('exams.manage', [
            'days' => $exams->groupBy(fn (Exam $exam) => $exam->date->toDateString()),
            'count' => $exams->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, 'add');

        if (Exam::current()->count() >= self::MAX) {
            return back()->withInput()->withErrors(['course_code' => 'The exam timetable can hold '.self::MAX.' papers.'], 'add');
        }

        $exam = Exam::create($data);
        Audit::record('exam_added', $exam, ['course' => $exam->course_code, 'date' => $exam->date->toDateString()]);

        return redirect()->to(route('exams.manage').'#date-'.$exam->date->toDateString())
            ->with('status', "{$exam->course_code} added on {$exam->date->format('D j M')}.");
    }

    public function update(Request $request, Exam $exam): RedirectResponse
    {
        abort_if($exam->archived_at !== null, 404);

        $exam->update($this->validated($request, 'exam-'.$exam->id));
        Audit::record('exam_updated', $exam, ['course' => $exam->course_code, 'date' => $exam->date->toDateString()]);

        return redirect()->to(route('exams.manage').'#date-'.$exam->date->toDateString())
            ->with('status', "{$exam->course_code} saved.");
    }

    public function destroy(Exam $exam): RedirectResponse
    {
        abort_if($exam->archived_at !== null, 404);

        $exam->delete();
        Audit::record('exam_removed', $exam, ['course' => $exam->course_code, 'date' => $exam->date->toDateString()]);

        return redirect()->route('exams.manage')->with('status', "{$exam->course_code} removed from the exam timetable.");
    }

    /**
     * Replaces the exam timetable with a spreadsheet's.
     */
    public function import(Request $request): RedirectResponse
    {
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
            $parsed = ExamImport::parse(SpreadsheetRows::read((string) $file->getRealPath(), $file->getClientOriginalExtension()));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['exams' => $exception->getMessage().' Save it from Excel as .xlsx or CSV and try again.'], 'import');
        }

        if (! $parsed['headings']) {
            return back()->withErrors(['exams' => 'The headings weren\'t found. Use the template: a heading row with Date, Start and Course code, then one paper per row.'], 'import');
        }

        if ($parsed['exams'] === []) {
            return back()->withErrors(['exams' => 'No papers were found in this file. Each row needs a date, a start time and a course code.'], 'import')
                ->with('skipped', array_slice($parsed['skipped'], 0, 10));
        }

        if (count($parsed['exams']) > self::MAX) {
            return back()->withErrors(['exams' => 'The exam timetable can hold '.self::MAX.' papers; this file has '.count($parsed['exams']).'.'], 'import');
        }

        DB::transaction(function () use ($parsed) {
            Exam::query()->whereNull('archived_at')->delete();

            foreach ($parsed['exams'] as $row) {
                Exam::create($row);
            }
        });

        Audit::record('exams_imported', null, ['file' => $file->getClientOriginalName(), 'papers' => count($parsed['exams']), 'skipped' => count($parsed['skipped'])]);

        return redirect()->route('exams.manage')
            ->with('status', 'Exam timetable uploaded.')
            ->with('import', ['count' => count($parsed['exams']), 'skipped' => array_slice($parsed['skipped'], 0, 20), 'skippedCount' => count($parsed['skipped'])]);
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
     * @return array{date: string, starts_at: string, ends_at: string|null, course_code: string, course_title: string|null, venue: string|null, levels: list<string>|null, programmes: list<string>|null, arms: list<string>|null, note: string|null}
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
            'programmes' => ['nullable', 'array'],
            'programmes.*' => [Rule::enum(Programme::class)],
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
            'programmes' => self::list($data['programmes'] ?? null),
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
