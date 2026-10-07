<?php

namespace App\Console\Commands;

use App\Support\Elections\ElectionLifecycle;
use Illuminate\Console\Command;

class CloseElections extends Command
{
    protected $signature = 'elections:close';

    protected $description = 'Close elections whose voting time is over and publish their final results';

    public function handle(): int
    {
        $closed = ElectionLifecycle::closeDue();

        if ($closed > 0) {
            $this->info("Closed {$closed} ".str('election')->plural($closed).'.');
        }

        return self::SUCCESS;
    }
}
