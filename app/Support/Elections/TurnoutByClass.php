<?php

namespace App\Support\Elections;

use App\Enums\Level;
use App\Enums\Programme;
use App\Models\Election;
use App\Support\Classes\Arms;
use App\Support\Classes\SchoolClass;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * How many students in each class could vote, and how many did. Built
 * only from the record that someone voted (election_voters), never from
 * the ballots, so it says nothing about who anyone chose.
 *
 * A student's class is the roll's when the election is limited to the
 * roll, else the one on their account, the same as for eligibility.
 */
class TurnoutByClass
{
    /**
     * @return list<array{key: string, label: string, eligible: int, held: int, voted: int, turnout: float}>
     */
    public static function for(Election $election): array
    {
        $columns = $election->classColumns();
        $rows = [];

        foreach (['eligible' => $election->electorate(), 'held' => $election->heldVoters(), 'voted' => self::voters($election)] as $count => $query) {
            foreach (self::grouped($query, $columns) as $group) {
                $key = $group->programme.'|'.$group->level.'|'.$group->arm;
                $rows[$key] ??= ['key' => $key, 'eligible' => 0, 'held' => 0, 'voted' => 0];
                $rows[$key][$count] += (int) $group->total;
            }
        }

        $order = array_flip(array_map(fn (SchoolClass $class) => $class->key(), SchoolClass::all()));

        uksort($rows, fn (string $a, string $b) => [$order[$a] ?? PHP_INT_MAX, $a] <=> [$order[$b] ?? PHP_INT_MAX, $b]);

        return array_values(array_map(fn (array $row) => [
            'key' => $row['key'],
            'label' => self::label($row['key']),
            'eligible' => $row['eligible'],
            'held' => $row['held'],
            'voted' => $row['voted'],
            'turnout' => $row['eligible'] > 0 ? round(min(100, $row['voted'] / $row['eligible'] * 100), 1) : 0.0,
        ], $rows));
    }

    /**
     * Everyone who voted in the election, joined to where their class is
     * kept. Left joins: someone who has since left the roll still counts.
     */
    private static function voters(Election $election): QueryBuilder
    {
        if ($election->isForDepartment()) {
            return DB::table('organization_election_voters')
                ->where('organization_election_voters.election_id', $election->id)
                ->join('voters', 'voters.id', '=', 'organization_election_voters.voter_id')
                ->leftJoin('organization_roll', function ($join) {
                    $join->on('organization_roll.matric_number', '=', 'voters.matric_number')
                        ->on('organization_roll.organization_id', '=', 'voters.organization_id');
                });
        }

        $query = DB::table('election_voters')
            ->where('election_voters.election_id', $election->id)
            ->join('users', 'users.id', '=', 'election_voters.user_id');

        if ($election->roll_only) {
            $query->leftJoin('nominal_roll', 'nominal_roll.matric_number', '=', 'users.matric_number');
        }

        return $query;
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     * @param  array{programme: Expression|string, level: Expression|string, arm: Expression|string|null}  $columns
     * @return list<object{programme: ?string, level: ?string, arm: ?string, total: int}>
     */
    private static function grouped(Builder|QueryBuilder $query, array $columns): array
    {
        $base = $query instanceof Builder ? $query->toBase() : $query;
        $grammar = $base->getGrammar();
        $sql = fn (Expression|string|null $column) => match (true) {
            $column === null => 'null',
            $column instanceof Expression => (string) $column->getValue($grammar),
            default => $grammar->wrap($column),
        };
        [$programme, $level, $arm] = [$sql($columns['programme']), $sql($columns['level']), $sql($columns['arm'])];

        /** @var list<object{programme: ?string, level: ?string, arm: ?string, total: int}> */
        return $base->selectRaw("{$programme} as programme, {$level} as level, {$arm} as arm, count(*) as total")
            ->groupByRaw($columns['arm'] === null ? "{$programme}, {$level}" : "{$programme}, {$level}, {$arm}")
            ->get()
            ->all();
    }

    /** "HND1 SWD Full-time", or what is known of a class the school doesn't list. */
    private static function label(string $key): string
    {
        [$programme, $level, $arm] = explode('|', $key, 3);
        $class = SchoolClass::make(Programme::tryFrom($programme), Level::tryFrom($level), $arm);

        if ($class !== null) {
            return $class->label();
        }

        $parts = array_filter([Level::tryFrom($level)?->label() ?? $level, Arms::short($arm === '' ? null : $arm), Programme::tryFrom($programme)?->label() ?? $programme]);

        return $parts === [] ? 'Class not known' : implode(' ', $parts);
    }
}
