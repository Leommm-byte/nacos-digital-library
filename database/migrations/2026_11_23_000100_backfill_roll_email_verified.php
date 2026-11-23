<?php

use App\Support\Elections\RollEmailVerification;
use Illuminate\Database\Migrations\Migration;

/**
 * Students who already confirmed their class email (signed up through the
 * link sent there and set a password) have proved it, so they shouldn't be
 * gated or re-emailed once the class-email vote gate goes live
 * (docs/vote-integrity.md). Marks them verified from what's already on the
 * roll. Re-runnable through RollEmailVerification::backfill().
 */
return new class extends Migration
{
    public function up(): void
    {
        RollEmailVerification::backfill();
    }

    public function down(): void
    {
        // Leaving accounts verified does no harm; nothing to undo.
    }
};
