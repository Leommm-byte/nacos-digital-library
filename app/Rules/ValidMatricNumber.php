<?php

namespace App\Rules;

use App\Support\MatricNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A matric number students can have today (see MatricNumber::problem), with
 * a message saying what is wrong.
 */
class ValidMatricNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $problem = MatricNumber::problem(is_string($value) ? $value : null);

        if ($problem !== null) {
            $fail($problem);
        }
    }
}
