<?php

namespace App\Support\Departments;

use App\Enums\ElectionStatus;
use App\Enums\Level;
use App\Enums\Programme;
use App\Models\Election;
use App\Models\Organization;
use App\Models\OrganizationRollEntry;
use Illuminate\Support\Facades\DB;

/**
 * A department's nominal roll, saved class by class (programme and level)
 * like the NACOS roll (RollImport reads the files for both).
 */
class DepartmentRoll
{
    /**
     * With $replace the rows become the whole class: students no longer on
     * it are removed. Students listed under another class move to this one.
     *
     * @param  array<string, array{matric_number: string, fullname: string|null, email: string|null}>  $rows
     * @return array{total: int, added: int, removed: int, moved: int}
     */
    public static function saveClass(Organization $organization, array $rows, Programme $programme, Level $level, bool $replace = true): array
    {
        return DB::transaction(function () use ($organization, $rows, $programme, $level, $replace) {
            $incoming = array_keys($rows);
            $inClass = $organization->roll()->where('programme', $programme)->where('level', $level)->pluck('matric_number')->all();

            $moved = 0;
            foreach (array_chunk($incoming, 500) as $chunk) {
                $moved += $organization->roll()->whereIn('matric_number', $chunk)
                    ->where(fn ($query) => $query->where('programme', '!=', $programme)->orWhere('level', '!=', $level)->orWhereNull('programme')->orWhereNull('level'))
                    ->count();
            }

            $removed = $replace ? array_values(array_diff($inClass, $incoming)) : [];
            foreach (array_chunk($removed, 500) as $chunk) {
                $organization->roll()->whereIn('matric_number', $chunk)->delete();
            }

            $now = now();
            foreach (array_chunk(array_values($rows), 500) as $chunk) {
                OrganizationRollEntry::query()->upsert(
                    array_map(fn (array $row) => [
                        ...$row,
                        'organization_id' => $organization->id,
                        'programme' => $programme->value,
                        'level' => $level->value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $chunk),
                    ['organization_id', 'matric_number'],
                    ['fullname', 'email', 'programme', 'level', 'updated_at'],
                );
            }

            return [
                'total' => $replace ? count($incoming) : count(array_unique([...$inClass, ...$incoming])),
                'added' => count(array_diff($incoming, $inClass)) - $moved,
                'removed' => count($removed),
                'moved' => $moved,
            ];
        });
    }

    /**
     * The department's open election that locks its roll: while voting is
     * open nobody can be added or removed to sway it.
     */
    public static function lockedBy(Organization $organization): ?Election
    {
        return $organization->elections()->where('status', ElectionStatus::Open)->orderBy('ends_at')->first();
    }
}
