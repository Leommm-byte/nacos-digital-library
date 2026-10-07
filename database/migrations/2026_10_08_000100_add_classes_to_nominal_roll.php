<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The nominal roll is kept class by class (programme and level), so each
 * class's list can be uploaded and replaced on its own. Elections can also
 * be limited by programme.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nominal_roll', function (Blueprint $table) {
            $table->string('programme', 16)->nullable()->after('level');
            $table->string('email')->nullable()->after('fullname');
            $table->index(['programme', 'level']);
        });

        Schema::table('elections', function (Blueprint $table) {
            $table->json('programmes')->nullable()->after('levels');
        });
    }

    public function down(): void
    {
        Schema::table('elections', function (Blueprint $table) {
            $table->dropColumn('programmes');
        });

        Schema::table('nominal_roll', function (Blueprint $table) {
            $table->dropIndex(['programme', 'level']);
            $table->dropColumn(['programme', 'email']);
        });
    }
};
