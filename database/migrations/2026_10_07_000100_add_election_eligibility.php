<?php

use App\Support\MatricNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-election eligibility: an election can be limited to some levels and
 * to a range of entry years. The entry year comes from the matric number
 * (F/ND/24/… → 2024) and is stored on the user so the electorate can be
 * counted with a plain query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('entry_year')->nullable()->after('matric_number')->index();
        });

        Schema::table('elections', function (Blueprint $table) {
            $table->json('levels')->nullable()->after('description');
            $table->unsignedSmallInteger('entry_year_from')->nullable()->after('levels');
            $table->unsignedSmallInteger('entry_year_to')->nullable()->after('entry_year_from');
            $table->timestamp('closed_at')->nullable()->after('ends_at');
        });

        DB::table('users')->select(['id', 'matric_number'])->orderBy('id')->chunkById(500, function ($users) {
            foreach ($users as $user) {
                DB::table('users')->where('id', $user->id)->update([
                    'entry_year' => MatricNumber::entryYear($user->matric_number),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('elections', function (Blueprint $table) {
            $table->dropColumn(['levels', 'entry_year_from', 'entry_year_to', 'closed_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('entry_year');
        });
    }
};
