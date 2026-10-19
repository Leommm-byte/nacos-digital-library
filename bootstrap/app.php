<?php

use App\Http\Controllers\Operations\HealthController;
use App\Http\Middleware\EnforceAccountState;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ServerTiming;
use App\Support\Operations\Alerts;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // For the uptime monitor: outside the web group, so checks start no
        // session (docs/deployment.md).
        then: function () {
            Route::get('/up/scheduler', [HealthController::class, 'scheduler'])->name('up.scheduler');
            Route::get('/up/backups', [HealthController::class, 'backups'])->name('up.backups');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(ServerTiming::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->web(append: [EnforceAccountState::class]);
        $middleware->alias(['role' => EnsureRole::class]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('home'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Errors on the live site are emailed to ALERT_EMAIL, and still logged.
        $exceptions->report(fn (Throwable $e) => Alerts::error($e));
    })->create();
