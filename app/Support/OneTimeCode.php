<?php

namespace App\Support;

/**
 * Random codes people read and type: recovery codes and admin/rep reset
 * codes. Uses an alphabet without look-alikes (no 0/O, 1/I/L) and stores
 * only a keyed hash (HMAC with the app key). The codes are random enough
 * that a slow password hash adds nothing, and checking 8 recovery codes stays
 * instant.
 */
class OneTimeCode
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * e.g. "K7QM-3XRP" for groups of 4, 2 groups.
     */
    public static function generate(int $groupLength = 4, int $groups = 2): string
    {
        $parts = [];

        for ($g = 0; $g < $groups; $g++) {
            $part = '';
            for ($i = 0; $i < $groupLength; $i++) {
                $part .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $parts[] = $part;
        }

        return implode('-', $parts);
    }

    /**
     * Uppercase, without spaces or dashes, so "k7qm 3xrp" matches "K7QM-3XRP".
     */
    public static function normalize(string $code): string
    {
        return strtoupper(preg_replace('/[\s-]+/', '', $code) ?? '');
    }

    public static function hash(string $code): string
    {
        return hash_hmac('sha256', self::normalize($code), self::key());
    }

    public static function matches(string $code, string $hash): bool
    {
        return hash_equals($hash, self::hash($code));
    }

    private static function key(): string
    {
        return (string) config('app.key');
    }
}
