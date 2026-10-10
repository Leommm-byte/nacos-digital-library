<?php

namespace App\Http\Controllers\Timetables;

use App\Http\Controllers\Controller;
use App\Models\TimetableSlot;
use App\Models\User;
use App\Support\Audit;
use App\Support\Classes\SchoolClass;
use App\Support\Spreadsheets\SpreadsheetRows;
use App\Support\Timetables\TimetableImport;
use App\Support\Timetables\TimetableTemplate;
use App\Support\Timetables\Week;
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
 * Class timetables: each class sees its own week; its governor (or an
 * admin) keeps it, one lecture at a time or by uploading a spreadsheet.
 * Admins can open any class's.
 */
class TimetableController extends Controller
{
    public function show(Request $request): View
    {
        $class = $this->chosenClass($request);

        if ($class === null) {
            return view('timetables.show', ['class' => null, 'week' => null, 'count' => 0, 'classes' => $this->classOptions()]);
        }

        Gate::authorize('view-timetable', $class);
        $slots = TimetableSlot::forClass($class)->get();

        return view('timetables.show', [
            'class' => $class,
            'week' => Week::build($slots),
            'count' => $slots->count(),
            'classes' => $this->classOptions(),
        ]);
    }

    public function edit(Request $request): View
    {
        $class = $this->chosenClass($request);

        if ($class === null) {
            abort(404);
        }

        Gate::authorize('edit-timetable', $class);
        $slots = TimetableSlot::forClass($class)->get();

        return view('timetables.edit', [
            'class' => $class,
            'slots' => $slots->groupBy('day'),
            'count' => $slots->count(),
            'classes' => $this->classOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $class = $this->postedClass($request);

        $data = $this->validated($request, 'add');

        if (TimetableSlot::forClass($class)->count() >= TimetableSlot::MAX_PER_CLASS) {
            return back()->withInput()->withErrors(['course_code' => 'A timetable can hold '.TimetableSlot::MAX_PER_CLASS.' lectures. Remove some first.'], 'add');
        }

        $slot = new TimetableSlot($data);
        $slot->assignClass($class);
        $slot->updated_by = $this->userId($request);
        $slot->save();

        Audit::record('timetable_slot_added', $slot, ['class' => $class->label(), 'course' => $slot->course_code, 'day' => $slot->dayName()]);

        return redirect()->to(route('timetable.edit', ['class' => $class->key()]).'#day-'.$slot->day)
            ->with('status', "{$slot->course_code} added on {$slot->dayName()}.");
    }

    public function update(Request $request, TimetableSlot $slot): RedirectResponse
    {
        $class = $this->editableClass($slot);
        $slot->fill($this->validated($request, 'slot-'.$slot->id));
        $slot->updated_by = $this->userId($request);
        $slot->save();

        Audit::record('timetable_slot_updated', $slot, ['class' => $class->label(), 'course' => $slot->course_code, 'day' => $slot->dayName()]);

        return redirect()->to(route('timetable.edit', ['class' => $class->key()]).'#day-'.$slot->day)
            ->with('status', "{$slot->course_code} saved.");
    }

    public function destroy(TimetableSlot $slot): RedirectResponse
    {
        $class = $this->editableClass($slot);
        $slot->delete();

        Audit::record('timetable_slot_removed', $slot, ['class' => $class->label(), 'course' => $slot->course_code, 'day' => $slot->dayName()]);

        return redirect()->to(route('timetable.edit', ['class' => $class->key()]).'#day-'.$slot->day)
            ->with('status', "{$slot->course_code} removed from {$slot->dayName()}.");
    }

    /**
     * Replaces the class's timetable with a spreadsheet's.
     */
    public function import(Request $request): RedirectResponse
    {
        $class = $this->postedClass($request);

        $request->validateWithBag('import', [
            'timetable' => ['required', 'file', 'max:2048', 'extensions:xlsx,csv,txt'],
        ], [
            'timetable.required' => 'Choose the timetable file (Excel or CSV).',
            'timetable.extensions' => 'Upload the Excel file (.xlsx) or a CSV.',
            'timetable.max' => 'The file must be 2 MB or smaller.',
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('timetable');

        try {
            $parsed = TimetableImport::parse(SpreadsheetRows::read((string) $file->getRealPath(), $file->getClientOriginalExtension()));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['timetable' => $exception->getMessage().' Save it from Excel as .xlsx or CSV and try again.'], 'import');
        }

        if (! $parsed['headings']) {
            return back()->withErrors(['timetable' => 'The headings weren\'t found. Use the template: a heading row with Day, Start, End and Course code, then one lecture per row.'], 'import');
        }

        if ($parsed['class'] !== null && $parsed['class']->key() !== $class->key()) {
            return back()->withErrors(['timetable' => "This file is for {$parsed['class']->label()}, not {$class->label()}. Check the file."], 'import');
        }

        if ($parsed['slots'] === []) {
            return back()->withErrors(['timetable' => 'No lectures were found in this file. Each row needs a day, start and end times and a course code.'], 'import')
                ->with('skipped', array_slice($parsed['skipped'], 0, 10));
        }

        if (count($parsed['slots']) > TimetableSlot::MAX_PER_CLASS) {
            return back()->withErrors(['timetable' => 'A timetable can hold '.TimetableSlot::MAX_PER_CLASS.' lectures; this file has '.count($parsed['slots']).'.'], 'import');
        }

        $userId = $this->userId($request);

        DB::transaction(function () use ($class, $parsed, $userId) {
            TimetableSlot::forClass($class)->delete();

            foreach ($parsed['slots'] as $row) {
                $slot = new TimetableSlot($row);
                $slot->assignClass($class);
                $slot->updated_by = $userId;
                $slot->save();
            }
        });

        Audit::record('timetable_imported', null, [
            'class' => $class->label(),
            'file' => $file->getClientOriginalName(),
            'lectures' => count($parsed['slots']),
            'skipped' => count($parsed['skipped']),
        ]);

        return redirect()->route('timetable.edit', ['class' => $class->key()])
            ->with('status', "Timetable for {$class->label()} uploaded.")
            ->with('import', ['count' => count($parsed['slots']), 'skipped' => array_slice($parsed['skipped'], 0, 20), 'skippedCount' => count($parsed['skipped'])]);
    }

    public function template(Request $request): BinaryFileResponse
    {
        $class = $this->chosenClass($request);

        return response()
            ->download(TimetableTemplate::classTimetable($class), TimetableTemplate::filename('timetable', $class?->label()), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    /**
     * The class whose timetable to show: an admin's choice, otherwise the
     * student's own class.
     */
    private function chosenClass(Request $request): ?SchoolClass
    {
        /** @var User $user */
        $user = $request->user();

        if (Gate::allows('view-all-timetables') && $request->filled('class')) {
            return SchoolClass::fromKey($request->string('class')->toString());
        }

        return $user->schoolClass() ?? (Gate::allows('view-all-timetables') ? SchoolClass::all()[0] : null);
    }

    /**
     * Every class, for an admin's class switcher; none for anyone else.
     *
     * @return array<string, string>
     */
    private function classOptions(): array
    {
        if (! Gate::allows('view-all-timetables')) {
            return [];
        }

        $options = [];
        foreach (SchoolClass::all() as $class) {
            $options[$class->key()] = $class->label();
        }

        return $options;
    }

    /**
     * The class a form was sent for, which the user must be allowed to edit.
     */
    private function postedClass(Request $request): SchoolClass
    {
        $class = SchoolClass::fromKey($request->string('class')->toString());

        if ($class === null) {
            abort(404);
        }

        Gate::authorize('edit-timetable', $class);

        return $class;
    }

    private function editableClass(TimetableSlot $slot): SchoolClass
    {
        $class = $slot->archived_at === null ? $slot->schoolClass() : null;

        if ($class === null) {
            abort(404);
        }

        Gate::authorize('edit-timetable', $class);

        return $class;
    }

    /**
     * @return array{day: int, starts_at: string, ends_at: string, course_code: string, course_title: string|null, lecturer: string|null, venue: string|null}
     */
    private function validated(Request $request, string $bag): array
    {
        $data = $request->validateWithBag($bag, [
            'day' => ['required', 'integer', Rule::in(array_keys(TimetableSlot::DAYS))],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'course_code' => ['required', 'string', 'max:20'],
            'course_title' => ['nullable', 'string', 'max:150'],
            'lecturer' => ['nullable', 'string', 'max:150'],
            'venue' => ['nullable', 'string', 'max:100'],
        ], [
            'day.required' => 'Choose the day.',
            'ends_at.after' => 'The lecture must end after it starts.',
            'course_code.required' => 'Enter the course code, for example CSC 201.',
        ]);

        $text = fn (string $key) => isset($data[$key]) && trim((string) $data[$key]) !== '' ? trim((string) preg_replace('/\s+/', ' ', (string) $data[$key])) : null;

        return [
            'day' => (int) $data['day'],
            'starts_at' => (string) $data['starts_at'],
            'ends_at' => (string) $data['ends_at'],
            'course_code' => strtoupper((string) $text('course_code')),
            'course_title' => $text('course_title'),
            'lecturer' => $text('lecturer'),
            'venue' => $text('venue'),
        ];
    }

    private function userId(Request $request): int
    {
        /** @var User $user */
        $user = $request->user();

        return $user->id;
    }
}
