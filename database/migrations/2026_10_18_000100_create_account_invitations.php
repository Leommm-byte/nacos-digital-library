<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Emailed invitations to accounts made from the nominal roll: a one-time
 * link to choose a password, sent a few at a time by the scheduler
 * (invitations:send). Only a hash of the link's token is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('token_hash', 64)->nullable()->unique();
            // Sign-ups and re-sends go ahead of a whole class's invitations.
            $table->boolean('priority')->default(false);
            $table->unsignedSmallInteger('sends')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['accepted_at', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_invitations');
    }
};
