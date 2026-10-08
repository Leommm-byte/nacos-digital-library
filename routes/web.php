<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BookController as AdminBookController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Announcements\AnnouncementController;
use App\Http\Controllers\Assistant\AssistantController;
use App\Http\Controllers\Elections\BallotSetupController;
use App\Http\Controllers\Elections\ElectionController;
use App\Http\Controllers\Elections\ManageElectionController;
use App\Http\Controllers\Elections\NominalRollController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Library\BookCoverController;
use App\Http\Controllers\Library\BookmarkController;
use App\Http\Controllers\Library\LibraryController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Reader\BookFileController;
use App\Http\Controllers\Reader\ReaderController;
use App\Http\Controllers\Reader\ReadingProgressController;
use App\Http\Controllers\Review\ReviewController;
use App\Http\Controllers\Uploads\UploadController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

require __DIR__.'/auth.php';

// Election pages and results are public, for transparency; voting needs an
// account.
Route::get('/elections', [ElectionController::class, 'index'])->name('elections.index');
Route::get('/elections/{election}', [ElectionController::class, 'show'])->whereNumber('election')->name('elections.show');

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
    Route::get('/uploads/{book}/edit', [UploadController::class, 'edit'])->name('uploads.edit');
    Route::put('/uploads/{book}', [UploadController::class, 'update'])->name('uploads.update');
    Route::delete('/uploads/{book}', [UploadController::class, 'destroy'])->name('uploads.destroy');
    Route::get('/uploads/{book}/status', [UploadController::class, 'status'])
        ->middleware('throttle:60,1')
        ->name('uploads.status');

    Route::middleware('can:review-uploads')->group(function () {
        Route::get('/review', [ReviewController::class, 'index'])->name('review.index');
        Route::get('/review/{book}', [ReviewController::class, 'show'])->name('review.show');
        Route::post('/review/{book}', [ReviewController::class, 'decide'])
            ->middleware('throttle:60,1')
            ->name('review.decide');
    });

    Route::get('/announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
    Route::middleware('can:manage-announcements')->group(function () {
        Route::get('/announcements/manage', [AnnouncementController::class, 'manage'])->name('announcements.manage');
        Route::get('/announcements/create', [AnnouncementController::class, 'create'])->name('announcements.create');
        Route::post('/announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
        Route::get('/announcements/{announcement}/edit', [AnnouncementController::class, 'edit'])->name('announcements.edit');
        Route::put('/announcements/{announcement}', [AnnouncementController::class, 'update'])->name('announcements.update');
        Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy'])->name('announcements.destroy');
    });

    Route::post('/elections/{election}/ballot', [ElectionController::class, 'vote'])
        ->whereNumber('election')
        ->middleware('throttle:10,1')
        ->name('elections.vote');

    Route::middleware('can:manage-elections')->group(function () {
        Route::get('/nominal-roll', [NominalRollController::class, 'index'])->name('roll.index');
        Route::get('/nominal-roll/template', [NominalRollController::class, 'template'])->name('roll.template');
        Route::post('/nominal-roll', [NominalRollController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('roll.store');
        Route::post('/nominal-roll/students', [NominalRollController::class, 'storeOne'])->name('roll.students.store');
        Route::delete('/nominal-roll/students/{entry}', [NominalRollController::class, 'destroy'])->name('roll.students.destroy');
    });

    Route::middleware('can:manage-elections')->prefix('elections/manage')->name('elections.manage')->group(function () {
        Route::get('/', [ManageElectionController::class, 'index'])->name('');
        Route::get('/create', [ManageElectionController::class, 'create'])->name('.create');
        Route::post('/', [ManageElectionController::class, 'store'])->name('.store');

        Route::scopeBindings()->prefix('{election}')->group(function () {
            Route::get('/', [ManageElectionController::class, 'show'])->name('.show');
            Route::get('/edit', [ManageElectionController::class, 'edit'])->name('.edit');
            Route::put('/', [ManageElectionController::class, 'update'])->name('.update');
            Route::delete('/', [ManageElectionController::class, 'destroy'])->name('.destroy');
            Route::post('/launch', [ManageElectionController::class, 'launch'])->name('.launch');
            Route::post('/close', [ManageElectionController::class, 'close'])->name('.close');

            Route::post('/positions', [BallotSetupController::class, 'storePosition'])->name('.positions.store');
            Route::put('/positions/{position}', [BallotSetupController::class, 'updatePosition'])->name('.positions.update');
            Route::post('/positions/{position}/move', [BallotSetupController::class, 'movePosition'])->name('.positions.move');
            Route::delete('/positions/{position}', [BallotSetupController::class, 'destroyPosition'])->name('.positions.destroy');
            Route::post('/positions/{position}/candidates', [BallotSetupController::class, 'storeCandidate'])->name('.candidates.store');
            Route::put('/positions/{position}/candidates/{candidate}', [BallotSetupController::class, 'updateCandidate'])->name('.candidates.update');
            Route::delete('/positions/{position}/candidates/{candidate}', [BallotSetupController::class, 'destroyCandidate'])->name('.candidates.destroy');
        });
    });

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', AdminDashboardController::class)->name('dashboard');

        Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
        Route::put('/users/{user}/role', [AdminUserController::class, 'updateRole'])->name('users.role');
        Route::put('/users/{user}/status', [AdminUserController::class, 'updateStatus'])->name('users.status');
        Route::put('/users/{user}/class', [AdminUserController::class, 'updateClass'])->name('users.class');
        Route::delete('/users/{user}/two-factor', [AdminUserController::class, 'resetTwoFactor'])->name('users.two-factor');
        Route::post('/users/{user}/password', [AdminUserController::class, 'resetPassword'])->name('users.password');

        Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
        Route::post('/accounts', [AccountController::class, 'store'])->middleware('throttle:20,1')->name('accounts.store');
        Route::get('/accounts/slips', [AccountController::class, 'slips'])->name('accounts.slips');

        Route::get('/books', [AdminBookController::class, 'index'])->name('books.index');
        Route::delete('/books/{book}', [AdminBookController::class, 'destroy'])->name('books.destroy');

        Route::get('/reports', ReportController::class)->name('reports');
        Route::get('/audit', AuditLogController::class)->name('audit');

        Route::get('/settings', [SettingController::class, 'edit'])->name('settings');
        Route::put('/settings', [SettingController::class, 'update'])->name('settings.update');
        Route::put('/settings/departments/{department}', [SettingController::class, 'department'])->name('settings.department');
    });

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('/notifications/{id}', [NotificationController::class, 'open'])
        ->whereUuid('id')
        ->name('notifications.open');

    Route::get('/assistant', [AssistantController::class, 'index'])->name('assistant.index');
    // AI answers take a few seconds each; the limit stops scripted floods.
    Route::post('/assistant', [AssistantController::class, 'store'])
        ->middleware('throttle:12,1')
        ->name('assistant.store');
    Route::delete('/assistant', [AssistantController::class, 'destroy'])->name('assistant.destroy');

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
