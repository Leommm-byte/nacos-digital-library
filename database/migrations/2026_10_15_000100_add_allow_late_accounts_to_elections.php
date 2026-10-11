<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * By default only accounts that existed when voting opened can vote, so an
 * unregistered classmate's matric number can't be signed up and used while
 * the election runs. Admins can allow late accounts per election.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('elections', function (Blueprint $table) {
            $table->boolean('allow_late_accounts')->default(false)->after('roll_only');
        });
    }

    public function down(): void
    {
        Schema::table('elections', function (Blueprint $table) {
            $table->dropColumn('allow_late_accounts');
        });
    }
};
