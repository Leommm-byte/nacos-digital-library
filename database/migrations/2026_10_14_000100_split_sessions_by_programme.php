<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Full-time, part-time and CODFEL keep separate calendars: each has its
 * own session and exam timetable. Exams belong to one programme (within
 * it, still limited by levels and courses), and a new session is started
 * for one programme at a time.
 */
return new class extends Migration
{
    private const PROGRAMMES = ['full_time', 'part_time', 'codfel'];

    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->string('programme', 16)->nullable()->after('id');
        });

        // A paper for one programme stays with it; a paper for several (or
        // everyone) is copied to each.
        foreach (DB::table('exams')->orderBy('id')->get() as $exam) {
            $programmes = json_decode((string) $exam->programmes, true);
            $programmes = is_array($programmes) && $programmes !== [] ? array_values($programmes) : self::PROGRAMMES;

            DB::table('exams')->where('id', $exam->id)->update(['programme' => array_shift($programmes)]);

            foreach ($programmes as $programme) {
                $copy = (array) $exam;
                unset($copy['id']);
                DB::table('exams')->insert([...$copy, 'programme' => $programme]);
            }
        }

        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn('programmes');
            $table->index(['programme', 'archived_at', 'date']);
        });

        Schema::table('session_changes', function (Blueprint $table) {
            $table->string('programme', 16)->nullable()->after('id')->index();
        });

        // The one session and exam switch become each programme's.
        $settings = DB::table('settings')->whereIn('key', ['academic_session', 'exams_published'])->pluck('value', 'key');
        $now = now();
        foreach (self::PROGRAMMES as $programme) {
            foreach ($settings as $key => $value) {
                DB::table('settings')->updateOrInsert(['key' => $key.'_'.$programme], ['value' => $value, 'updated_at' => $now]);
            }
        }

        // Settings are cached forever; read them afresh.
        Cache::forget('settings:all');
    }

    public function down(): void
    {
        Schema::table('session_changes', function (Blueprint $table) {
            $table->dropIndex(['programme']);
            $table->dropColumn('programme');
        });

        Schema::table('exams', function (Blueprint $table) {
            $table->dropIndex(['programme', 'archived_at', 'date']);
            $table->json('programmes')->nullable();
        });

        foreach (DB::table('exams')->get(['id', 'programme']) as $exam) {
            DB::table('exams')->where('id', $exam->id)->update(['programmes' => json_encode([$exam->programme])]);
        }

        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn('programme');
        });
    }
};
