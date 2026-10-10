<?php

namespace App\Support\Timetables;

use App\Models\TimetableSlot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A class's week laid out for the page: the lecture days (Monday to Friday,
 * and Saturday when anything is on), today's lectures marked done, now or
 * next, and the next lecture to come, in Lagos time.
 */
class Week
{
    /**
     * @param  Collection<int, TimetableSlot>  $slots  in day and time order
     * @return array{
     *     days: array<int, array{name: string, short: string, today: bool, slots: list<array{slot: TimetableSlot, state: string|null}>}>,
     *     today: int,
     *     next: TimetableSlot|null,
     *     now: TimetableSlot|null,
     * }
     */
    public static function build(Collection $slots, ?Carbon $at = null): array
    {
        $at = ($at ?? now())->copy()->timezone((string) config('app.display_timezone', 'Africa/Lagos'));
        $today = $at->dayOfWeekIso;
        $minute = $at->hour * 60 + $at->minute;
        $lastDay = $slots->contains(fn (TimetableSlot $slot) => $slot->day === 6) ? 6 : 5;

        $days = [];
        for ($day = 1; $day <= $lastDay; $day++) {
            $days[$day] = ['name' => TimetableSlot::DAYS[$day], 'short' => substr(TimetableSlot::DAYS[$day], 0, 3), 'today' => $day === $today, 'slots' => []];
        }

        $now = null;
        $next = null;

        foreach ($slots as $slot) {
            $state = null;

            if ($slot->day === $today) {
                $state = match (true) {
                    TimeOfDay::minutes($slot->ends_at) <= $minute => 'done',
                    TimeOfDay::minutes($slot->starts_at) <= $minute => 'now',
                    default => null,
                };
                $now ??= $state === 'now' ? $slot : null;
            }

            if (isset($days[$slot->day])) {
                $days[$slot->day]['slots'][] = ['slot' => $slot, 'state' => $state];
            }
        }

        // The next lecture to start: later today, then later this week,
        // then from the start of next week.
        $ordered = $slots->sortBy(fn (TimetableSlot $slot) => ($slot->day - $today + 7) % 7 * 1440 + TimeOfDay::minutes($slot->starts_at)
            + ($slot->day === $today && TimeOfDay::minutes($slot->starts_at) <= $minute ? 7 * 1440 : 0));
        $next = $ordered->first();

        foreach ($days as $day => $info) {
            foreach ($info['slots'] as $i => $item) {
                if ($next !== null && $item['slot']->is($next) && $item['state'] === null && $day === $today) {
                    $days[$day]['slots'][$i]['state'] = 'next';
                }
            }
        }

        return ['days' => $days, 'today' => $today, 'next' => $next, 'now' => $now];
    }
}
