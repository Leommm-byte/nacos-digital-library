<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 10);
            $table->text('body');
            $table->json('links')->nullable();
            // A question answered by AI (counts towards the daily limit), or
            // an answer written by AI.
            $table->boolean('ai')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
    }
};
