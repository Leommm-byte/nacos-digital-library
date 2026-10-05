<?php

namespace App\Support;

/**
 * Time-based one-time passwords (RFC 6238), as used by Google Authenticator,
 * Microsoft Authenticator, Aegis and similar apps: SHA-1, 6 digits, 30-second
 * steps. Framework-free so it can be tested against the RFC's own vectors.
 */
class Totp
{
    public const DIGITS = 6;

    public const PERIOD = 30;

    /** Steps either side of now that are accepted, for clock drift. */
    public const WINDOW = 1;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * A new random secret, base32-encoded (160 bits, as RFC 4226 recommends).
     */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * The code for a given time step.
     */
    public static function at(string $secret, int $step, int $digits = self::DIGITS): string
    {
        return self::hotp(self::base32Decode($secret), $step, $digits);
    }

    public static function stepFor(int $timestamp): int
    {
        return intdiv($timestamp, self::PERIOD);
    }

    /**
     * Returns the time step the code belongs to, or null if it doesn't
     * match. Steps at or before $afterStep are refused, so a code can't be
     * replayed once it has been used.
     */
    public static function verify(string $secret, string $code, int $timestamp, ?int $afterStep = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return null;
        }

        $key = self::base32Decode($secret);
        $now = self::stepFor($timestamp);

        for ($step = $now - self::WINDOW; $step <= $now + self::WINDOW; $step++) {
            if ($afterStep !== null && $step <= $afterStep) {
                continue;
            }

            if (hash_equals(self::hotp($key, $step, self::DIGITS), $code)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * The URI authenticator apps read from the QR code.
     */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * "ABCD EFGH IJKL …" for typing the secret in by hand.
     */
    public static function formatForDisplay(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    private static function hotp(string $key, int $counter, int $digits): string
    {
        $hash = hash_hmac('sha1', pack('J', $counter), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $value): string
    {
        $value = strtoupper(preg_replace('/[\s=]+/', '', $value) ?? '');
        $bits = '';

        foreach (str_split($value) as $char) {
            $index = strpos(self::BASE32, $char);
            if ($index === false) {
                return '';
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int) bindec($byte));
            }
        }

        return $out;
    }
}
