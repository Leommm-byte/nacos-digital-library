<?php

namespace Database\Seeders;

use App\Enums\Level;
use App\Enums\Programme;
use App\Enums\VoterRole;
use App\Models\Election;
use App\Models\Organization;
use App\Models\OrganizationRollEntry;
use App\Models\Voter;
use App\Support\Elections\BallotBox;
use App\Support\Elections\ElectionLifecycle;
use App\Support\Elections\LiveResults;
use Illuminate\Database\Seeder;

/**
 * A demo department election space (docs/department-elections.md), for
 * local development and the screenshots: Food Science and Technology at
 * /vote/food-science, with an admin, a roll, registered voters, an open
 * election, a closed one and a draft.
 *
 * Accounts (password Password1!): admin@fst.test (the department's admin)
 * and F/ND/24/3101001 (a voter who hasn't voted yet).
 */
class DemoDepartmentSeeder extends Seeder
{
    public const SLUG = 'food-science';

    public function run(): void
    {
        if (Organization::query()->where('slug', self::SLUG)->exists()) {
            return;
        }

        $fst = Organization::create([
            'name' => 'Food Science and Technology',
            'short_name' => 'FST',
            'slug' => self::SLUG,
            'contact_name' => 'FST Electoral Committee',
            'contact_email' => 'electoral@fst.test',
        ]);

        $admin = new Voter(['fullname' => 'Funmi Electoral', 'email' => 'admin@fst.test', 'password' => 'Password1!']);
        $admin->organization_id = $fst->id;
        $admin->role = VoterRole::Admin;
        $admin->last_login_at = now()->subHour();
        $admin->save();

        $names = ['Kunle Adebayo', 'Ngozi Eze', 'Yemi Ojo', 'Halima Bello', 'Chinedu Obi', 'Bisi Akande', 'Femi Lawal', 'Amaka Nwosu',
            'Sola Ade', 'Ibrahim Musa', 'Tope Alabi', 'Uche Okeke', 'Zainab Sani', 'Dayo Ogun', 'Ronke Bakare', 'Emeka Ugwu',
            'Lola Peters', 'Segun Ige', 'Funke Ajayi', 'Kemi Salami', 'Tunde Bakare', 'Ada Okoro', 'Musa Danjuma', 'Nkechi Ibe'];

        $voters = collect();
        foreach ($names as $i => $name) {
            $level = $i % 2 === 0 ? Level::ND1 : Level::ND2;
            $matric = sprintf('F/ND/%02d/31010%02d', $level === Level::ND1 ? 25 : 24, $i + 1);
            if ($i === 0) {
                $matric = 'F/ND/24/3101001';
                $level = Level::ND2;
            }

            OrganizationRollEntry::create([
                'organization_id' => $fst->id,
                'matric_number' => $matric,
                'fullname' => $name,
                'level' => $level,
                'programme' => Programme::FullTime,
            ]);

            // Most of the roll has registered.
            if ($i < 18) {
                $voter = new Voter(['fullname' => $name, 'matric_number' => $matric, 'password' => 'Password1!']);
                $voter->organization_id = $fst->id;
                $voter->created_at = now()->subDays(3);
                $voter->save();
                $voters->push($voter);
            }
        }

        // Closed last week, with final results.
        $past = $this->election($fst, 'ND1 Class Representative', 'Who speaks for ND1 at departmental meetings.', ['Class Rep' => ['Ngozi Eze', 'Halima Bello', 'Bisi Akande']], ['levels' => ['ND1']]);
        ElectionLifecycle::launch($past, 6);
        $this->vote($past, $voters->filter(fn (Voter $voter) => str_contains((string) $voter->matric_number, '/25/'))->take(7));
        ElectionLifecycle::close($past);
        $past->forceFill(['starts_at' => now()->subDays(8), 'ends_at' => now()->subDays(7), 'closed_at' => now()->subDays(7)])->save();
        LiveResults::publish($past);

        // Open now: Kunle (F/ND/24/3101001) hasn't voted yet.
        $open = $this->election($fst, 'FST Students\' Association Election 2026', 'Choose the executives of the Food Science and Technology Students\' Association for 2026/2027.', [
            'President' => ['Yemi Ojo', 'Chinedu Obi'],
            'General Secretary' => ['Amaka Nwosu', 'Femi Lawal', 'Uche Okeke'],
        ]);
        ElectionLifecycle::launch($open, 24);
        $this->vote($open, $voters->slice(1, 11));

        $this->election($fst, 'FST Week Planning Committee', 'Three students to plan FST week.', ['Committee Chair' => ['Sola Ade']]);
    }

    /**
     * @param  array<string, list<string>>  $positions
     * @param  array<string, mixed>  $attributes
     */
    private function election(Organization $fst, string $title, string $description, array $positions, array $attributes = []): Election
    {
        $election = new Election(['title' => $title, 'description' => $description, 'roll_only' => true, ...$attributes]);
        $election->organization_id = $fst->id;
        $election->save();

        $order = 0;
        foreach ($positions as $positionTitle => $candidates) {
            $position = $election->positions()->create(['title' => $positionTitle, 'sort_order' => $order++]);
            foreach ($candidates as $i => $name) {
                $position->candidates()->create(['name' => $name, 'sort_order' => $i]);
            }
        }

        return $election;
    }

    /**
     * @param  iterable<Voter>  $voters
     */
    private function vote(Election $election, iterable $voters): void
    {
        $positions = $election->positions()->with('candidates')->get();

        foreach ($voters as $voter) {
            BallotBox::cast($election, $voter, $positions->mapWithKeys(fn ($position) => [$position->id => $position->candidates->random()->id])->all());
        }

        LiveResults::refresh($election);
    }
}
