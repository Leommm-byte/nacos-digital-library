<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Votes in someone else's name (docs/vote-integrity.md). A ballot now
 * needs proof that the voter controls the class-list email on the nominal
 * roll, the one address an impersonator can't set. Proof is clicking a
 * one-time link emailed to that address; `roll_email_verified_at` records
 * it. An election opts in with `require_roll_email`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('roll_email_verified_at')->nullable()->after('email_verified_at');
            $table->index('roll_email_verified_at');
        });

        Schema::table('elections', function (Blueprint $table) {
            $table->boolean('require_roll_email')->default(true)->after('roll_only');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['roll_email_verified_at']);
            $table->dropColumn('roll_email_verified_at');
        });

        Schema::table('elections', function (Blueprint $table) {
            $table->dropColumn('require_roll_email');
        });
    }
};
