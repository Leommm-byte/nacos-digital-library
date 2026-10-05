<?php

use App\Models\Book;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// Temporary home page. Replaced by the dashboard in the dashboard PR.
Route::get('/', function () {
    // Local only: proves the stack works end to end.
    $status = app()->isLocal() ? [
        'Environment' => app()->environment(),
        'Database' => DB::connection()->getDriverName().' connected',
        'Departments' => Department::count(),
        'Users' => User::count(),
        'Books' => Book::count(),
    ] : null;

    return view('home', ['status' => $status]);
})->name('home');

require __DIR__.'/auth.php';

// Every component and token on one page, for building and reviewing UI.
if (! app()->isProduction()) {
    Route::view('/styleguide', 'styleguide')->name('styleguide');
}
