<?php

namespace App\Console\Commands;

use App\Support\Elections\ElectionLifecycle;
use App\Support\Elections\LiveResults;
use Illuminate\Console\Command;

class CloseElections extends Command
{
    protected $signature = 'elections:close';

    protected $description = 'Close elections whose voting time is over, publish their final results, and catch up live results';

    public function handle(): int
    {
        $closed = ElectionLifecycle::closeDue();
        LiveResults::catchUpAll();

        if ($closed > 0) {
            $this->info("Closed {$closed} ".str('election')->plural($closed).'.');
        }

        return self::SUCCESS;
    }
}
