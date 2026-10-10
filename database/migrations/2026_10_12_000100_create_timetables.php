<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Timetables: each class's weekly lectures (kept by its governor or an
 * admin, seen by the class) and the exam timetable (kept by admins, seen by
 * everyone). A new session archives them (archived_at) instead of deleting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_slots', function (Blueprint $table) {
            $table->id();
            // The class: see App\Support\Classes\SchoolClass.
            $table->string('programme', 16);
            $table->string('level', 8);
            $table->string('arm', 16)->nullable();
            // 1 Monday to 6 Saturday.
            $table->unsignedTinyInteger('day');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('course_code', 20);
            $table->string('course_title', 150)->nullable();
            $table->string('lecturer', 150)->nullable();
            $table->string('venue', 100)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['programme', 'level', 'arm', 'archived_at']);
        });

        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->time('starts_at');
            $table->time('ends_at')->nullable();
            $table->string('course_code', 20);
            $table->string('course_title', 150)->nullable();
            $table->string('venue', 100)->nullable();
            // Who sits it; empty means everyone (like an election's limits).
            $table->json('levels')->nullable();
            $table->json('programmes')->nullable();
            $table->json('arms')->nullable();
            $table->string('note', 200)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['archived_at', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exams');
        Schema::dropIfExists('timetable_slots');
    }
};
