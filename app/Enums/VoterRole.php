<?php

namespace App\Enums;

/**
 * Accounts in a department's election space: students who vote, and the
 * department's own admins who run its roll and elections.
 */
enum VoterRole: string
{
    case Voter = 'voter';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Voter => 'Voter',
            self::Admin => 'Admin',
        };
    }
}
