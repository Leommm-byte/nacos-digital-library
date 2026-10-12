<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Support\Audit;
use App\Support\MatricNumber;
use Illuminate\Console\Command;

/**
 * The first admin on a new server: sign up on the site, then run this over
 * SSH. Later admins are made from Admin → Users.
 */
class MakeAdmin extends Command
{
    protected $signature = 'users:make-admin {matric : The matric number of an account that already exists}';

    protected $description = 'Make an existing account an admin (for the first admin on a new server)';

    public function handle(): int
    {
        $matric = MatricNumber::normalize((string) $this->argument('matric'));
        $user = User::query()->where('matric_number', $matric)->first();

        if ($user === null) {
            $this->error("No account has the matric number {$matric}. Sign up on the site first.");

            return self::FAILURE;
        }

        $from = $user->role;
        $user->forceFill(['role' => Role::Admin])->save();
        Audit::record('user_role_changed', $user, ['from' => $from->value, 'to' => Role::Admin->value, 'name' => $user->fullname, 'via' => 'command']);

        $this->info("{$user->fullname} ({$user->matric_number}) is now an admin.");

        return self::SUCCESS;
    }
}
