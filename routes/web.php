<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\Library\BookCoverController;
use App\Http\Controllers\Library\BookmarkController;
use App\Http\Controllers\Library\LibraryController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

require __DIR__.'/auth.php';

Route::middleware('auth')->group(function () {
    Route::get('/library', [LibraryController::class, 'index'])->name('library.index');
    Route::get('/library/{book}', [LibraryController::class, 'show'])->name('library.show');
    Route::get('/library/{book}/cover', BookCoverController::class)->name('library.cover');

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
