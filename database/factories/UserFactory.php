<?php

namespace Database\Factories;

use App\Enums\Level;
use App\Enums\Programme;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\User;
use App\Support\Classes\Arms;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        $level = fake()->randomElement(Level::cases());
        $programme = fake()->randomElement(Programme::cases());
        $type = $level->stage() === 'HND' ? 'HD' : 'ND';
        $arms = Arms::forStage($level->stage());

        if ($arms === []) {
            $digits = (string) fake()->unique()->numberBetween(1000000, 9999999);
        } else {
            // An HND number carries its course (arm) digit: 3211… is SWD.
            $position = (int) config('classes.arm_digit', 4) - 1;
            $rest = (string) fake()->unique()->numberBetween(100000, 999999);
            $digits = substr($rest, 0, $position).fake()->randomElement(array_column($arms, 'digit')).substr($rest, $position);
        }

        return [
            'fullname' => fake()->name(),
            'matric_number' => sprintf('%s/%s/%02d/%s', $programme->matricLetter(), $type, fake()->numberBetween(20, 25), $digits),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'department_id' => Department::factory(),
            'level' => $level,
            'programme' => $programme,
            'password' => static::$password ??= Hash::make('Password1!'),
            'remember_token' => Str::random(10),
        ];
    }

    public function role(Role $role): static
    {
        return $this->afterMaking(fn (User $user) => $user->role = $role);
    }

    public function suspended(): static
    {
        return $this->afterMaking(fn (User $user) => $user->status = UserStatus::Suspended);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}
