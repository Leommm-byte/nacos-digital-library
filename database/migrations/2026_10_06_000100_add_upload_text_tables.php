<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Text read from a book's pages (by the uploader's device, then
        // optionally improved by AI), so its contents are searchable. Kept
        // out of `books` so listing books never loads it, with its own
        // full-text index so title matches can rank above it.
        Schema::create('book_texts', function (Blueprint $table) {
            $table->foreignId('book_id')->primary()->constrained()->cascadeOnDelete();
            $table->mediumText('text');

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->fullText('text');
            }
        });

        Schema::table('book_files', function (Blueprint $table) {
            // none: no text; device: read on the uploader's device;
            // queued: AI pass waiting or running; done: AI pass finished.
            $table->string('text_status', 16)->default('none')->after('page_count');
        });

        // Scanned pages kept only while the AI pass is pending.
        Schema::create('book_scan_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_file_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('page');
            $table->string('path')->nullable();
            $table->mediumText('text')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->unique(['book_file_id', 'page']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_scan_pages');

        Schema::table('book_files', function (Blueprint $table) {
            $table->dropColumn('text_status');
        });

        Schema::dropIfExists('book_texts');
    }
};
