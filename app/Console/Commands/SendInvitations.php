<?php

namespace App\Console\Commands;

use App\Support\Accounts\Invitations;
use Illuminate\Console\Command;

class SendInvitations extends Command
{
    protected $signature = 'invitations:send';

    protected $description = 'Email the waiting account invitations, within the hourly sending limit';

    public function handle(): int
    {
        $sent = Invitations::sendDue();

        if ($sent > 0) {
            $this->info("Sent {$sent} ".str('invitation')->plural($sent).'.');
        }

        return self::SUCCESS;
    }
}
