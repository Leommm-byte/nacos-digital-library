<?php

namespace App\Support;

/**
 * Random first passwords for accounts an admin creates, printed on a slip:
 * easy to read and type (no 0/O, 1/I/L), and changed at first login.
 */
class TemporaryPassword
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public static function make(): string
    {
        $characters = '';

        for ($i = 0; $i < 10; $i++) {
            $characters .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return substr($characters, 0, 5).'-'.substr($characters, 5);
    }
}
