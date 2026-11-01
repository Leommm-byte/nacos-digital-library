<?php

/*
| Department election spaces (docs/department-elections.md): /vote/{slug}.
| Each department NACOS approves runs its own elections here, with its own
| accounts (the "voter" guard), roll and admins. The "vote" middleware
| finds the department, stops a paused one, and fills {organization} in
| every route() on its pages.
*/

use App\Http\Controllers\Vote\Admin\BallotController;
use App\Http\Controllers\Vote\Admin\ElectionController as AdminElectionController;
use App\Http\Controllers\Vote\Admin\RollController;
use App\Http\Controllers\Vote\Admin\VoterController;
use App\Http\Controllers\Vote\AuthController;
use App\Http\Controllers\Vote\ElectionController;
use App\Http\Controllers\Vote\HomeController;
use App\Http\Controllers\Vote\LogoController;
use App\Http\Controllers\Vote\PasswordController;
use App\Http\Controllers\Vote\RegisterController;
use Illuminate\Support\Facades\Route;

Route::prefix('vote/{organization}')->middleware('vote')->name('vote.')->group(function () {
    Route::get('/', HomeController::class)->name('home');
    Route::get('/logo', LogoController::class)->name('logo');
    Route::get('/elections/{election}', [ElectionController::class, 'show'])->whereNumber('election')->name('elections.show');
    Route::get('/elections/{election}/candidates/{candidate}/photo', [ElectionController::class, 'photo'])
        ->whereNumber(['election', 'candidate'])
        ->name('candidates.photo');

    Route::middleware('vote.guest')->group(function () {
        Route::get('/login', [AuthController::class, 'create'])->name('login');
        // Failed sign-ins are limited in AuthController, not per address here:
        // a whole campus can share one IP on election day.
        Route::post('/login', [AuthController::class, 'store'])->name('login.store');
        Route::get('/register', [RegisterController::class, 'create'])->name('register');
        // Per-IP cap on new accounts. Generous: a whole class can register
        // together on one campus IP, and only matric numbers on the roll can
        // register at all, each once.
        Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:120,10')->name('register.store');
        Route::get('/forgot-password', [PasswordController::class, 'forgot'])->name('password.forgot');
    });

    Route::middleware('vote.auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
        Route::get('/password', [PasswordController::class, 'edit'])->name('password.edit');
        Route::put('/password', [PasswordController::class, 'update'])->name('password.update');
        Route::post('/elections/{election}/ballot', [ElectionController::class, 'vote'])
            ->whereNumber('election')
            // Per voter, not per address (AppServiceProvider).
            ->middleware('throttle:department-ballot')
            ->name('elections.vote');
    });

    // The department's admins, or a NACOS admin doing the work for them.
    Route::middleware('vote.admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/roll', [RollController::class, 'index'])->name('roll');
        Route::get('/roll/template', [RollController::class, 'template'])->name('roll.template');
        Route::post('/roll', [RollController::class, 'store'])->middleware('throttle:department-admin')->name('roll.store');
        Route::post('/roll/students', [RollController::class, 'storeOne'])->name('roll.students.store');
        Route::delete('/roll/students/{entry}', [RollController::class, 'destroy'])->whereNumber('entry')->name('roll.students.destroy');

        Route::get('/voters', [VoterController::class, 'index'])->name('voters');
        Route::post('/voters/{voter}/password', [VoterController::class, 'password'])->whereNumber('voter')->name('voters.password');
        Route::put('/voters/{voter}/status', [VoterController::class, 'status'])->whereNumber('voter')->name('voters.status');

        // The same setup screens as NACOS's own elections (ElectionDesk).
        Route::prefix('elections')->name('elections')->group(function () {
            Route::get('/', [AdminElectionController::class, 'index'])->name('');
            Route::get('/create', [AdminElectionController::class, 'create'])->name('.create');
            Route::post('/', [AdminElectionController::class, 'store'])->name('.store');

            Route::scopeBindings()->prefix('{election}')->whereNumber('election')->group(function () {
                Route::get('/', [AdminElectionController::class, 'show'])->name('.show');
                Route::get('/edit', [AdminElectionController::class, 'edit'])->name('.edit');
                Route::put('/', [AdminElectionController::class, 'update'])->name('.update');
                Route::delete('/', [AdminElectionController::class, 'destroy'])->name('.destroy');
                Route::post('/launch', [AdminElectionController::class, 'launch'])->name('.launch');
                Route::post('/close', [AdminElectionController::class, 'close'])->name('.close');

                Route::post('/positions', [BallotController::class, 'storePosition'])->name('.positions.store');
                Route::put('/positions/{position}', [BallotController::class, 'updatePosition'])->name('.positions.update');
                Route::post('/positions/{position}/move', [BallotController::class, 'movePosition'])->name('.positions.move');
                Route::delete('/positions/{position}', [BallotController::class, 'destroyPosition'])->name('.positions.destroy');
                Route::post('/positions/{position}/candidates', [BallotController::class, 'storeCandidate'])->name('.candidates.store');
                Route::put('/positions/{position}/candidates/{candidate}', [BallotController::class, 'updateCandidate'])->name('.candidates.update');
                Route::delete('/positions/{position}/candidates/{candidate}', [BallotController::class, 'destroyCandidate'])->name('.candidates.destroy');
            });
        });
    });
});
