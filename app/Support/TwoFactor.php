<?php

namespace App\Support;

use App\Models\User;

/**
 * Checks a code from the user's authenticator app or one of their recovery
 * codes. Both are single use: the TOTP time step is remembered and a
 * recovery code is removed once it has worked.
 */
class TwoFactor
{
    public const RECOVERY_CODE_COUNT = 8;

    /**
     * @return 'totp'|'recovery'|null how the user proved it, or null if the code is wrong
     */
    public static function verify(User $user, string $code): ?string
    {
        if (! $user->hasTwoFactorEnabled()) {
            return null;
        }

        $step = Totp::verify((string) $user->two_factor_secret, $code, now()->getTimestamp(), $user->two_factor_last_step);

        if ($step !== null) {
            $user->forceFill(['two_factor_last_step' => $step])->save();

            return 'totp';
        }

        $codes = $user->two_factor_recovery_codes ?? [];

        foreach ($codes as $index => $hash) {
            if (OneTimeCode::matches($code, $hash)) {
                unset($codes[$index]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return 'recovery';
            }
        }

        return null;
    }

    /**
     * Fresh recovery codes: stores their hashes on the user and returns the
     * plain codes, to be shown once.
     *
     * @return list<string>
     */
    public static function replaceRecoveryCodes(User $user): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $codes[] = OneTimeCode::generate(5, 2);
        }

        $user->forceFill([
            'two_factor_recovery_codes' => array_map(OneTimeCode::hash(...), $codes),
        ])->save();

        return $codes;
    }

    public static function remainingRecoveryCodes(User $user): int
    {
        return count($user->two_factor_recovery_codes ?? []);
    }
}
