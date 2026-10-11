<?php

namespace App\Support\Sessions;

use App\Enums\ElectionStatus;
use App\Enums\Level;
use App\Enums\Programme;
use App\Enums\Role;
use App\Http\Controllers\Timetables\ExamController;
use App\Models\Election;
use App\Models\Exam;
use App\Models\RollEntry;
use App\Models\SessionChange;
use App\Models\TimetableSlot;
use App\Models\User;
use App\Support\Audit;
use App\Support\Classes\Arms;
use App\Support\Classes\SchoolClass;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Starting a new academic session, in one step:
 *
 * - every current student moves up a level, by their programme's length
 *   (config/classes.php); final years graduate (they keep the library,
 *   but can't vote or upload), and graduating course reps and governors
 *   become students;
 * - the nominal roll starts empty, for each class to be uploaded again;
 * - class and exam timetables are archived and the exam timetable is
 *   hidden;
 * - the academic session setting moves on.
 *
 * What changed is kept, so it can be undone until a new roll is uploaded.
 * Updates are a few set-based queries per class, not one per student, so a
 * whole school fits in one request on shared hosting.
 */
class NewSession
{
    /**
     * The level a student moves to from this one, or null when they
     * graduate (the last year of their programme, or past it).
     */
    public static function nextLevel(Programme $programme, Level $level): ?Level
    {
        $years = SchoolClass::years($programme, $level->stage());

        return $years !== null && $level->year() < $years ? Level::at($level->stage(), $level->year() + 1) : null;
    }

    /** "2026/2027" → "2027/2028"; without one, this year's. */
    public static function nextSession(?string $current): string
    {
        if ($current !== null && preg_match('#^(\d{4})/(\d{4})$#', $current, $match)) {
            return ((int) $match[1] + 1).'/'.((int) $match[2] + 1);
        }

        $year = now()->timezone((string) config('app.display_timezone'))->year;

        return $year.'/'.($year + 1);
    }

    /**
     * What starting a new session would do, class by class.
     *
     * @return array{
     *     classes: list<array{from: string, to: string|null, count: int}>,
     *     promoted: int,
     *     graduated: int,
     *     roll: int,
     *     lectures: int,
     *     exams: int,
     * }
     */
    public static function preview(): array
    {
        $order = array_flip(array_map(fn (SchoolClass $class) => $class->key(), SchoolClass::all()));
        $rows = User::query()->toBase()->whereNull('graduated_at')
            ->selectRaw('programme, level, arm, count(*) as total')
            ->groupBy('programme', 'level', 'arm')
            ->get();

        $classes = [];
        foreach ($rows as $row) {
            $programme = Programme::tryFrom((string) $row->programme);
            $level = Level::tryFrom((string) $row->level);

            if ($programme === null || $level === null) {
                continue;
            }

            $arm = $row->arm !== null ? (string) $row->arm : null;
            $next = self::nextLevel($programme, $level);
            $classes[] = [
                'from' => self::label($programme, $level, $arm),
                'to' => $next !== null ? self::label($programme, $next, Arms::forStage($next->stage()) !== [] ? $arm : null) : null,
                'count' => (int) $row->total,
                'sort' => $order[$programme->value.'|'.$level->value.'|'.($arm ?? '')] ?? PHP_INT_MAX,
            ];
        }

        usort($classes, fn (array $a, array $b) => [$a['sort'], $a['from']] <=> [$b['sort'], $b['from']]);

        return [
            'classes' => array_map(fn (array $class) => ['from' => $class['from'], 'to' => $class['to'], 'count' => $class['count']], $classes),
            'promoted' => array_sum(array_map(fn (array $class) => $class['to'] !== null ? $class['count'] : 0, $classes)),
            'graduated' => array_sum(array_map(fn (array $class) => $class['to'] === null ? $class['count'] : 0, $classes)),
            'roll' => RollEntry::query()->count(),
            'lectures' => TimetableSlot::query()->whereNull('archived_at')->count(),
            'exams' => Exam::query()->whereNull('archived_at')->count(),
        ];
    }

    /**
     * Why a new session can't start now, or null.
     */
    public static function blocker(): ?string
    {
        $open = Election::query()->where('status', ElectionStatus::Open)->first(['id', 'title']);

        return $open !== null ? "Voting is open in {$open->title}. Close it before starting a new session." : null;
    }

    public static function start(User $by, string $toSession): SessionChange
    {
        if (($blocker = self::blocker()) !== null) {
            throw new RuntimeException($blocker);
        }

        return DB::transaction(function () use ($by, $toSession) {
            $at = now()->startOfSecond();
            $preview = self::preview();

            $change = new SessionChange([
                'from_session' => Settings::get('academic_session'),
                'to_session' => $toSession,
                'started_at' => $at,
                'exams_were_published' => ExamController::published(),
                'roll' => json_encode(RollEntry::query()->toBase()
                    ->get(['matric_number', 'fullname', 'email', 'level', 'programme', 'arm', 'created_at', 'updated_at'])
                    ->map(fn ($row) => (array) $row)
                    ->all()),
                'summary' => [
                    'promoted' => $preview['promoted'],
                    'graduated' => $preview['graduated'],
                    'roll' => $preview['roll'],
                    'lectures' => $preview['lectures'],
                    'exams' => $preview['exams'],
                ],
            ]);
            $change->started_by = $by->id;
            $change->save();

            // Write down every student's level and role first, then move
            // them (moving ND1 to ND2 before ND2 is written down would carry
            // them on to HND).
            $moves = [];
            foreach (Programme::cases() as $programme) {
                foreach (Level::cases() as $level) {
                    $next = self::nextLevel($programme, $level);
                    $moves[] = [$programme, $level, $next];

                    DB::table('session_change_users')->insertUsing(
                        ['session_change_id', 'user_id', 'programme', 'from_level', 'to_level', 'from_role'],
                        DB::table('users')
                            ->selectRaw('?, id, programme, level, ?, role', [$change->id, $next?->value])
                            ->whereNull('graduated_at')
                            ->where('programme', $programme->value)
                            ->where('level', $level->value),
                    );
                }
            }

            foreach ($moves as [$programme, $level, $next]) {
                $moving = DB::table('session_change_users')->select('user_id')
                    ->where('session_change_id', $change->id)
                    ->where('programme', $programme->value)
                    ->where('from_level', $level->value);

                DB::table('users')->whereIn('id', $moving)->update($next !== null ? ['level' => $next->value] : ['graduated_at' => $at]);
            }

            // A graduating course rep or governor no longer has a class.
            DB::table('users')
                ->whereIn('id', DB::table('session_change_users')->select('user_id')->where('session_change_id', $change->id)->whereNull('to_level'))
                ->whereIn('role', [Role::CourseRep->value, Role::Governor->value])
                ->update(['role' => Role::Student->value]);

            RollEntry::query()->delete();
            TimetableSlot::query()->whereNull('archived_at')->update(['archived_at' => $at]);
            Exam::query()->whereNull('archived_at')->update(['archived_at' => $at]);
            Settings::put(['academic_session' => $toSession, 'exams_published' => 0]);

            Audit::record('session_started', $change, ['from' => $change->from_session, 'to' => $toSession, ...$change->summary ?? []]);

            return $change;
        });
    }

    /**
     * The latest new session, while it can still be undone: nobody has
     * uploaded a roll for it yet.
     */
    public static function undoable(): ?SessionChange
    {
        $latest = SessionChange::query()->latest('id')->first();

        return $latest !== null && $latest->undone_at === null && ! RollEntry::query()->exists() ? $latest : null;
    }

    public static function undo(SessionChange $change, User $by): void
    {
        if (self::undoable()?->id !== $change->id) {
            throw new RuntimeException('This new session can no longer be undone: a roll has been uploaded since, or it isn\'t the latest.');
        }

        DB::transaction(function () use ($change) {
            $at = $change->started_at;
            $groups = DB::table('session_change_users')->where('session_change_id', $change->id)
                ->select('from_level', 'to_level', 'from_role')->distinct()->get();

            foreach ($groups as $group) {
                $students = DB::table('session_change_users')->select('user_id')
                    ->where('session_change_id', $change->id)
                    ->where('from_level', $group->from_level)
                    ->where('from_role', $group->from_role)
                    ->when($group->to_level === null, fn ($query) => $query->whereNull('to_level'), fn ($query) => $query->where('to_level', $group->to_level));

                // Only students still where the new session put them: a
                // class an admin changed since is kept.
                DB::table('users')->whereIn('id', $students)
                    ->when($group->to_level === null,
                        fn ($query) => $query->where('graduated_at', $at),
                        fn ($query) => $query->whereNull('graduated_at')->where('level', $group->to_level))
                    ->update(['level' => $group->from_level, 'role' => $group->from_role, 'graduated_at' => null]);
            }

            /** @var list<array<string, mixed>> $roll */
            $roll = json_decode((string) $change->roll, true) ?: [];
            foreach (array_chunk($roll, 500) as $chunk) {
                DB::table('nominal_roll')->insert($chunk);
            }

            TimetableSlot::query()->where('archived_at', $at)->update(['archived_at' => null]);
            Exam::query()->where('archived_at', $at)->update(['archived_at' => null]);
            Settings::put(['academic_session' => $change->from_session, 'exams_published' => $change->exams_were_published ? 1 : 0]);

            $change->undone_at = now();
            $change->save();
        });

        Audit::record('session_undone', $change, ['from' => $change->to_session, 'back_to' => $change->from_session], $by->id);
    }

    private static function label(Programme $programme, Level $level, ?string $arm): string
    {
        return SchoolClass::make($programme, $level, $arm)?->label()
            ?? implode(' ', array_filter([$level->label(), Arms::short($arm), $programme->label()]));
    }
}
