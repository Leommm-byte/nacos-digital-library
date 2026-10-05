<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $production = $this->app->isProduction();

        // Outside production, fail loudly on N+1 queries (lazy loading),
        // silently discarded attributes and typos in attribute names.
        Model::shouldBeStrict(! $production);

        // Never allow migrate:fresh, db:wipe and friends against production.
        DB::prohibitDestructiveCommands($production);

        if ($production) {
            URL::forceHttps();
        }

        Password::defaults(function () use ($production) {
            $rule = Password::min(8)->letters()->mixedCase()->numbers()->symbols();

            return $production ? $rule->uncompromised() : $rule;
        });

        $this->defineGates();
        $this->customizeAuthEmails();
    }

    private function customizeAuthEmails(): void
    {
        ResetPassword::toMailUsing(function (mixed $user, string $token): MailMessage {
            assert($user instanceof User);
            $url = route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()]);

            return (new MailMessage)
                ->subject('Reset your '.config('app.name').' password')
                ->greeting('Hello '.$user->firstName().',')
                ->line('We received a request to reset the password for '.$user->matric_number.'.')
                ->action('Choose a new password', $url)
                ->line('The link works for 60 minutes and only once.')
                ->line("If you didn't ask for this, you can ignore this email. Your password won't change.");
        });

        VerifyEmail::toMailUsing(function (mixed $user, string $url): MailMessage {
            assert($user instanceof User);

            return (new MailMessage)
                ->subject('Verify your email for '.config('app.name'))
                ->greeting('Hello '.$user->firstName().',')
                ->line('Confirm this is your email address so you can reset your password by email if you ever forget it.')
                ->action('Verify email address', $url)
                ->line("If you didn't add this address to a NACOS YabaTech account, you can ignore this email.");
        });
    }

    /**
     * Abilities that aren't tied to one model. Model rules live in
     * app/Policies. Roles are ordered, so "governor" includes admins.
     */
    private function defineGates(): void
    {
        Gate::define('access-admin', fn (User $user) => $user->hasRole(Role::Admin));
        Gate::define('review-uploads', fn (User $user) => $user->hasRole(Role::Governor));
        // Who may open the reset-code page; which students they may issue
        // codes for is UserPolicy::issueResetCode.
        Gate::define('issue-reset-codes', fn (User $user) => $user->hasRole(Role::CourseRep));
    }
}
