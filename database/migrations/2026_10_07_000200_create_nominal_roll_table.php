<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The nominal roll: the official list of current students, imported by an
 * admin. Elections can be limited to students on it, so graduates and
 * invented matric numbers can't vote. When the roll lists a level, that
 * level counts instead of the one the student chose at signup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nominal_roll', function (Blueprint $table) {
            $table->id();
            $table->string('matric_number', 32)->unique();
            $table->string('fullname', 150)->nullable();
            $table->string('level', 8)->nullable();
            $table->timestamps();
        });

        Schema::table('elections', function (Blueprint $table) {
            $table->boolean('roll_only')->default(true)->after('entry_year_to');
        });
    }

    public function down(): void
    {
        Schema::table('elections', function (Blueprint $table) {
            $table->dropColumn('roll_only');
        });

        Schema::dropIfExists('nominal_roll');
    }
};
