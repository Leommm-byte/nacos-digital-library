<?php

namespace Database\Factories;

use App\Enums\ElectionStatus;
use App\Models\Election;
use App\Models\ElectionPosition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Election>
 */
class ElectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => 'NACOS '.fake()->randomElement(['Executive Council', 'Class Representative', 'Welfare Committee']).' Election',
            'description' => null,
            'levels' => null,
            'entry_year_from' => null,
            'entry_year_to' => null,
            // Tests opt in to the nominal roll where they need it.
            'roll_only' => false,
        ];
    }

    /**
     * Voting open now, closing in the given number of hours.
     */
    public function open(int $hours = 6): static
    {
        return $this->afterMaking(function (Election $election) use ($hours) {
            $election->status = ElectionStatus::Open;
            $election->starts_at = now()->subMinutes(30);
            $election->ends_at = now()->addHours($hours);
        });
    }

    public function closed(): static
    {
        return $this->afterMaking(function (Election $election) {
            $election->status = ElectionStatus::Closed;
            $election->starts_at = now()->subDay();
            $election->ends_at = now()->subHour();
            $election->closed_at = now()->subHour();
        });
    }

    /**
     * Positions with candidates: ['President' => 2, 'Treasurer' => 3].
     *
     * @param  array<string, int>  $positions
     */
    public function withBallot(array $positions = ['President' => 2, 'General Secretary' => 2]): static
    {
        return $this->afterCreating(function (Election $election) use ($positions) {
            $order = 0;

            foreach ($positions as $title => $candidates) {
                /** @var ElectionPosition $position */
                $position = $election->positions()->create(['title' => $title, 'sort_order' => $order++]);

                for ($i = 0; $i < $candidates; $i++) {
                    $position->candidates()->create([
                        'name' => fake()->name(),
                        'matric_number' => null,
                        'manifesto' => fake()->sentence(10),
                        'sort_order' => $i,
                    ]);
                }
            }
        });
    }
}
