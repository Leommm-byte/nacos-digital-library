<?php

namespace App\Http\Controllers\Vote\Admin;

use App\Http\Controllers\Elections\ManageElectionController;
use App\Models\Election;
use App\Support\Elections\DepartmentDesk;

/**
 * A department's admins set up and run its elections with the same
 * screens as NACOS (see ManageElectionController); routes, layout and
 * roll are the department's own.
 */
class ElectionController extends ManageElectionController
{
    use DepartmentDesk;

    protected function authorizeChange(string $ability, Election $election): void
    {
        $this->ensureDraft($election);
    }
}
