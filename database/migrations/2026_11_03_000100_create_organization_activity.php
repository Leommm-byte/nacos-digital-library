<?php

use App\Support\Departments\ActivityBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each department's own activity log (docs/department-elections.md):
 * registrations, sign-ins, votes (that someone voted, never how), roll
 * and election changes. Kept apart from NACOS's audit_logs, as the rest of
 * a department's data is.
 *
 * Who acted is a department account (voter_id) or a NACOS admin doing the
 * work for them (user_id), with their name and login kept as they were, so
 * the log still reads right after an account is removed.
 *
 * What already happened before this log existed is filled in
 * (ActivityBackfill).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_activity', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('voter_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // voter, nacos, or null for the system.
            $table->string('actor_type', 8)->nullable();
            $table->string('actor_name', 150)->nullable();
            $table->string('actor_login')->nullable();
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'action']);
            $table->index(['voter_id', 'created_at']);
        });

        ActivityBackfill::run();
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_activity');
    }
};
