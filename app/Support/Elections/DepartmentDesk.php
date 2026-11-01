<?php

namespace App\Support\Elections;

use App\Models\Election;
use App\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * What a department's admin controllers share: the department from the
 * URL (resolved by the "vote" middleware), and the rule that only drafts
 * change, as ElectionPolicy says for NACOS.
 */
trait DepartmentDesk
{
    protected function desk(): ElectionDesk
    {
        $organization = Organization::current();
        abort_unless($organization !== null, 404);

        return ElectionDesk::for($organization);
    }

    protected function ensureDraft(Election $election): void
    {
        abort_unless($this->desk()->owns($election), 404);

        if (! $election->isDraft()) {
            throw new AuthorizationException('This election has started, so its details, positions and candidates can no longer change.');
        }
    }
}
