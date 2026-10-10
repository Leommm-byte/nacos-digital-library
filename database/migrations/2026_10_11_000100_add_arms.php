<?php

use App\Support\Classes\Arms;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Courses (arms): HND is split into SWD and NCC, read from the matric
 * number (config/classes.php). Students and roll entries get their arm,
 * and elections can be limited to arms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('arm', 16)->nullable()->after('programme')->index();
        });

        Schema::table('nominal_roll', function (Blueprint $table) {
            $table->string('arm', 16)->nullable()->after('programme')->index();
        });

        Schema::table('elections', function (Blueprint $table) {
            $table->json('arms')->nullable()->after('programmes');
        });

        // Existing students and roll entries get the arm in their matric number.
        foreach (['users', 'nominal_roll'] as $name) {
            DB::table($name)->select(['id', 'matric_number'])->orderBy('id')->chunkById(500, function ($rows) use ($name) {
                foreach ($rows as $row) {
                    if (($arm = Arms::fromMatric($row->matric_number)) !== null) {
                        DB::table($name)->where('id', $row->id)->update(['arm' => $arm]);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('elections', function (Blueprint $table) {
            $table->dropColumn('arms');
        });

        foreach (['nominal_roll', 'users'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['arm']);
                $table->dropColumn('arm');
            });
        }
    }
};
