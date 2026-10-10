<?php

namespace Database\Factories;

use App\Enums\Level;
use App\Enums\Programme;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\User;
use App\Support\Classes\Arms;
use App\Support\Classes\SchoolClass;
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
        // A class the school runs; the matric number follows whatever level
        // and programme the account ends up with.
        /** @var SchoolClass $class */
        $class = fake()->randomElement(SchoolClass::all());

        return [
            'fullname' => fake()->name(),
            'matric_number' => fn (array $attributes) => self::matricFor($attributes['level'], $attributes['programme']),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'department_id' => Department::factory(),
            'level' => $class->level,
            'programme' => $class->programme,
            'password' => static::$password ??= Hash::make('Password1!'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * A unique matric number for the level and programme: the programme
     * letter, ND or HD, and for HND a course (arm) digit, as 3211… is SWD
     * (the arm given, or any).
     */
    public static function matricFor(Level|string $level, Programme|string $programme, ?string $arm = null): string
    {
        $level = $level instanceof Level ? $level : Level::from($level);
        $programme = $programme instanceof Programme ? $programme : Programme::from($programme);
        $arms = Arms::forStage($level->stage());

        if ($arms === []) {
            $digits = (string) fake()->unique()->numberBetween(1000000, 9999999);
        } else {
            $position = (int) config('classes.arm_digit', 4) - 1;
            $rest = (string) fake()->unique()->numberBetween(100000, 999999);
            $digit = $arm !== null && isset($arms[$arm]) ? $arms[$arm]['digit'] : fake()->randomElement(array_column($arms, 'digit'));
            $digits = substr($rest, 0, $position).$digit.substr($rest, $position);
        }

        return sprintf('%s/%s/%02d/%s', $programme->matricLetter(), $level->stage() === 'HND' ? 'HD' : 'ND', fake()->numberBetween(20, 25), $digits);
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
