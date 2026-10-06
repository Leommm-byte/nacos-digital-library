<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\Library\BookCoverController;
use App\Http\Controllers\Library\BookmarkController;
use App\Http\Controllers\Library\LibraryController;
use App\Http\Controllers\Reader\BookFileController;
use App\Http\Controllers\Reader\ReaderController;
use App\Http\Controllers\Reader\ReadingProgressController;
use App\Http\Controllers\Uploads\UploadController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

require __DIR__.'/auth.php';

Route::middleware('auth')->group(function () {
    Route::get('/library', [LibraryController::class, 'index'])->name('library.index');
    Route::get('/library/{book}', [LibraryController::class, 'show'])->name('library.show');
    Route::get('/library/{book}/cover', BookCoverController::class)->name('library.cover');

    Route::get('/library/{book}/read', [ReaderController::class, 'show'])->name('books.read');
    // PDF.js asks for the file in many small ranges, so the limit is generous.
    Route::get('/library/{book}/file', BookFileController::class)
        ->middleware('throttle:600,1')
        ->name('books.file');
    Route::post('/library/{book}/progress', [ReadingProgressController::class, 'store'])
        ->middleware('throttle:60,1')
        ->name('books.progress');

    Route::get('/upload', [UploadController::class, 'create'])->name('uploads.create');
    Route::post('/upload', [UploadController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('uploads.store');
    Route::get('/uploads', [UploadController::class, 'index'])->name('uploads.index');
    Route::get('/uploads/{book}', [UploadController::class, 'show'])->name('uploads.show');
    Route::get('/uploads/{book}/status', [UploadController::class, 'status'])
        ->middleware('throttle:60,1')
        ->name('uploads.status');

    Route::get('/saved', [BookmarkController::class, 'index'])->name('bookmarks.index');
    Route::post('/library/{book}/bookmark', [BookmarkController::class, 'store'])
        ->middleware('throttle:60,1')
        ->name('bookmarks.store');
    Route::delete('/library/{book}/bookmark', [BookmarkController::class, 'destroy'])
        ->middleware('throttle:60,1')
        ->name('bookmarks.destroy');
});

// Every component and token on one page, for building and reviewing UI.
if (! app()->isProduction()) {
    Route::view('/styleguide', 'styleguide')->name('styleguide');
}
