<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An announcement is for everyone (no class) or for one class: programme,
 * level and, for HND, the course (arm). Governors post to their own class;
 * admins to everyone or any class.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->string('programme', 20)->nullable()->after('body');
            $table->string('level', 10)->nullable()->after('programme');
            $table->string('arm', 10)->nullable()->after('level');
            $table->index(['programme', 'level', 'arm'], 'announcements_class_index');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropIndex('announcements_class_index');
            $table->dropColumn(['programme', 'level', 'arm']);
        });
    }
};
