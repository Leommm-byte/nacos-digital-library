<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A new academic session: students move up a level (final years
 * graduate), the nominal roll starts empty and timetables are archived.
 * Each new session keeps what it changed, so it can be undone until a new
 * roll is uploaded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Graduates keep their account to read the library, but can't
            // vote or upload.
            $table->timestamp('graduated_at')->nullable()->after('arm')->index();
        });

        Schema::create('session_changes', function (Blueprint $table) {
            $table->id();
            $table->string('from_session', 9)->nullable();
            $table->string('to_session', 9);
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            // Also the archived_at of the timetables it archived.
            $table->timestamp('started_at');
            $table->timestamp('undone_at')->nullable();
            $table->boolean('exams_were_published')->default(false);
            // The nominal roll as it was (JSON), to put back on undo.
            $table->longText('roll')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();
        });

        Schema::create('session_change_users', function (Blueprint $table) {
            $table->foreignId('session_change_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('programme', 16);
            $table->string('from_level', 8);
            // Null: graduated.
            $table->string('to_level', 8)->nullable();
            $table->string('from_role', 16);

            $table->primary(['session_change_id', 'user_id']);
            $table->index(['session_change_id', 'programme', 'from_level'], 'session_change_users_class_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_change_users');
        Schema::dropIfExists('session_changes');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['graduated_at']);
            $table->dropColumn('graduated_at');
        });
    }
};
