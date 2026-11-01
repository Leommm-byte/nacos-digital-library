<?php

namespace App\Http\Controllers\Vote\Admin;

use App\Http\Controllers\Elections\BallotSetupController;
use App\Models\Election;
use App\Support\Elections\DepartmentDesk;

/**
 * Positions and candidates of a department's draft election (see
 * BallotSetupController).
 */
class BallotController extends BallotSetupController
{
    use DepartmentDesk;

    protected function authorizeChange(Election $election): void
    {
        $this->ensureDraft($election);
    }
}
