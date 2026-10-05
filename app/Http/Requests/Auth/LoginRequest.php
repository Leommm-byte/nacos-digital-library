<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Support\Audit;
use App\Support\MatricNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Rate limiting never locks an account. Failed attempts are counted per
 * matric number *and* IP address, so someone guessing another student's
 * password only slows themselves down; the owner, on their own device, can
 * still sign in. A looser per-IP limit slows attempts spread across many
 * accounts. It is generous because a whole campus can share one IP.
 */
class LoginRequest extends FormRequest
{
    public const MAX_ATTEMPTS_PER_ACCOUNT = 5;

    public const MAX_ATTEMPTS_PER_IP = 100;

    /**
     * Checked when the matric number is unknown, so the response takes as
     * long as a real check and doesn't reveal which accounts exist.
     */
    private const DUMMY_HASH = '$2y$12$7DpYq2Wv8yzFkjW1qNBpyu7HHRDbUUolIAri7MfvDrv2fazZYLPFu';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'matric_number' => ['required', 'string', 'max:32'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['matric_number' => MatricNumber::normalize($this->input('matric_number'))]);
    }

    /**
     * Returns the user whose credentials were given, or throws.
     */
    public function authenticate(): User
    {
        $this->ensureIsNotRateLimited();

        $matric = $this->string('matric_number')->toString();
        $password = $this->string('password')->toString();
        $user = User::where('matric_number', $matric)->first();

        if (! Hash::check($password, $user?->password ?? self::DUMMY_HASH) || ! $user) {
            RateLimiter::hit($this->accountKey(), 60);
            RateLimiter::hit($this->ipKey(), 600);
            Audit::record('login_failed', $user, ['matric_number' => $matric]);

            throw ValidationException::withMessages([
                'matric_number' => "That matric number and password don't match.",
            ]);
        }

        RateLimiter::clear($this->accountKey());

        if ($user->isSuspended()) {
            Audit::record('login_blocked_suspended', $user);

            throw ValidationException::withMessages([
                'matric_number' => 'This account has been suspended. Contact a NACOS admin if you think this is a mistake.',
            ]);
        }

        return $user;
    }

    private function ensureIsNotRateLimited(): void
    {
        $account = RateLimiter::tooManyAttempts($this->accountKey(), self::MAX_ATTEMPTS_PER_ACCOUNT);
        $ip = RateLimiter::tooManyAttempts($this->ipKey(), self::MAX_ATTEMPTS_PER_IP);

        if (! $account && ! $ip) {
            return;
        }

        $seconds = max(
            $account ? RateLimiter::availableIn($this->accountKey()) : 0,
            $ip ? RateLimiter::availableIn($this->ipKey()) : 0,
        );

        Audit::record('login_throttled', null, [
            'matric_number' => $this->string('matric_number')->toString(),
            'scope' => $account ? 'account' : 'ip',
        ]);

        throw ValidationException::withMessages([
            'matric_number' => 'Too many attempts. Try again in '.($seconds > 60 ? ceil($seconds / 60).' minutes' : $seconds.' seconds').'.',
        ]);
    }

    private function accountKey(): string
    {
        return 'login:'.$this->string('matric_number')->lower()->toString().'|'.$this->ip();
    }

    private function ipKey(): string
    {
        return 'login-ip:'.$this->ip();
    }
}
