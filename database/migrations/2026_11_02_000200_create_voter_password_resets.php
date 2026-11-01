<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Password reset links for department election accounts
 * (docs/department-elections.md). Keyed by the account, not the email: the
 * same address can be used in two departments' spaces and in NACOS. Only a
 * hash of the link's token is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voter_password_resets', function (Blueprint $table) {
            $table->foreignId('voter_id')->primary()->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voter_password_resets');
    }
};
