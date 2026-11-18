<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stopping accounts registered in someone else's name from voting
 * (docs/vote-integrity.md).
 *
 * - users.vote_held_at: an admin has put the account on hold; it can't vote
 *   until an admin releases it (after checking the student in person).
 * - audit_logs.device_id: an anonymous id kept in a cookie on each browser
 *   (DeviceId middleware), so one phone signing up or voting for many
 *   accounts shows up.
 * - audit_logs.network_key: the address and browser together, hashed. Weaker
 *   than device_id (classmates share campus Wi-Fi and often the same phone
 *   and browser), but it exists for entries written before device_id did;
 *   filled in here for the sign-ups, sign-ins and votes already logged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('vote_held_at')->nullable()->index();
            $table->string('vote_hold_reason', 255)->nullable();
            $table->foreignId('vote_held_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('device_id', 32)->nullable()->index();
            $table->char('network_key', 40)->nullable()->index();
        });

        DB::table('audit_logs')
            ->whereIn('action', ['user_registered', 'login', 'election_voted'])
            ->whereNotNull('ip_address')
            ->orderBy('id')
            ->chunkById(1000, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('audit_logs')->where('id', $row->id)->update([
                        'network_key' => sha1($row->ip_address.'|'.$row->user_agent),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['device_id']);
            $table->dropIndex(['network_key']);
            $table->dropColumn(['device_id', 'network_key']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vote_held_by');
            $table->dropIndex(['vote_held_at']);
            $table->dropColumn(['vote_held_at', 'vote_hold_reason']);
        });
    }
};
