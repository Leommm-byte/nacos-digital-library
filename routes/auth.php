<?php

use App\Http\Controllers\Account\EmailController;
use App\Http\Controllers\Account\PasswordController;
use App\Http\Controllers\Account\ProfileController;
use App\Http\Controllers\Account\ResetCodeIssueController;
use App\Http\Controllers\Account\SettingsController;
use App\Http\Controllers\Account\TwoFactorController;
use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ResetCodeController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\SignupController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');

    Route::get('/login/two-factor', [TwoFactorChallengeController::class, 'create'])->name('two-factor.login');
    Route::post('/login/two-factor', [TwoFactorChallengeController::class, 'store'])->name('two-factor.login.store');

    Route::get('/signup', [SignupController::class, 'create'])->name('signup');
    // Per-IP cap on new accounts, generous enough for a shared campus IP.
    Route::post('/signup', [SignupController::class, 'store'])
        ->middleware('throttle:20,10')
        ->name('signup.store');

    Route::get('/forgot-password', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'store'])
        ->middleware('throttle:10,10')
        ->name('password.email');
    Route::get('/reset-password/{token}', [ResetPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [ResetPasswordController::class, 'store'])
        ->middleware('throttle:10,10')
        ->name('password.update');

    Route::get('/reset-with-code', [ResetCodeController::class, 'create'])->name('password.code');
    Route::post('/reset-with-code', [ResetCodeController::class, 'store'])->name('password.code.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/password/change', [ChangePasswordController::class, 'edit'])->name('password.change');
    Route::put('/password/change', [ChangePasswordController::class, 'update'])->name('password.change.update');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('/settings', [SettingsController::class, 'show'])->name('settings');
    Route::put('/settings/password', [PasswordController::class, 'update'])
        ->middleware('throttle:10,10')
        ->name('settings.password');

    Route::put('/settings/email', [EmailController::class, 'update'])->name('settings.email');
    Route::get('/email/verify/{id}/{hash}', [EmailController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('/email/verification-notification', [EmailController::class, 'resend'])
        ->middleware('throttle:3,10')
        ->name('verification.send');

    Route::post('/settings/two-factor', [TwoFactorController::class, 'start'])->name('two-factor.start');
    Route::get('/settings/two-factor/setup', [TwoFactorController::class, 'setup'])->name('two-factor.setup');
    Route::post('/settings/two-factor/confirm', [TwoFactorController::class, 'confirm'])
        ->middleware('throttle:10,1')
        ->name('two-factor.confirm');
    Route::post('/settings/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])
        ->middleware('throttle:10,1')
        ->name('two-factor.recovery-codes');
    Route::delete('/settings/two-factor', [TwoFactorController::class, 'destroy'])
        ->middleware('throttle:10,1')
        ->name('two-factor.destroy');

    Route::middleware('can:issue-reset-codes')->group(function () {
        Route::get('/reset-codes', [ResetCodeIssueController::class, 'create'])->name('reset-codes.create');
        Route::post('/reset-codes', [ResetCodeIssueController::class, 'store'])
            ->middleware('throttle:30,10')
            ->name('reset-codes.store');
    });
});
