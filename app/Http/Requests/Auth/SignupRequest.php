<?php

namespace App\Http\Requests\Auth;

use App\Enums\Level;
use App\Enums\Programme;
use App\Support\MatricNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class SignupRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fullname' => ['required', 'string', 'min:3', 'max:150'],
            'matric_number' => ['required', 'string', 'max:32', 'regex:'.MatricNumber::PATTERN, Rule::unique('users', 'matric_number')],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('is_active', true)],
            'level' => ['required', Rule::enum(Level::class)],
            'programme' => ['required', Rule::enum(Programme::class)],
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'matric_number.regex' => 'Enter your matric number as printed on your ID card, for example F/ND/24/1234567.',
            'matric_number.unique' => 'An account with this matric number already exists. Try logging in instead.',
            'email.unique' => 'Another account already uses this email address.',
            'department_id.required' => 'Choose your department.',
            'department_id.exists' => 'Choose your department.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['fullname' => 'full name'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'fullname' => preg_replace('/\s+/', ' ', trim((string) $this->input('fullname'))),
            'matric_number' => MatricNumber::normalize($this->input('matric_number')),
            'email' => strtolower(trim((string) $this->input('email'))) ?: null,
        ]);
    }
}
