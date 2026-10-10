<?php

namespace App\Http\Requests\Account;

use App\Enums\Level;
use App\Enums\Programme;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProfileRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // An active department, or the one they're already in.
        $currentDepartment = $this->user()?->department_id;
        $department = Rule::exists('departments', 'id')
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $currentDepartment));

        return [
            'fullname' => ['required', 'string', 'min:3', 'max:150'],
            'display_name' => ['nullable', 'string', 'max:30', 'regex:'.User::DISPLAY_NAME_PATTERN],
            'department_id' => ['required', 'integer', $department],
            'level' => ['required', Rule::enum(Level::class)],
            'programme' => ['required', Rule::enum(Programme::class)],
        ];
    }

    /**
     * Course reps (and above) act for one class (department + level), e.g.
     * when issuing reset codes, so they can't move themselves to another.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $user = $this->user();

                if (! $user instanceof User || ! $user->hasRole(Role::CourseRep)) {
                    return;
                }

                $message = 'Course reps can’t change their own department or level. Ask an admin.';

                if ((int) $this->input('department_id') !== $user->department_id) {
                    $validator->errors()->add('department_id', $message);
                }

                if ($this->input('level') !== $user->level->value) {
                    $validator->errors()->add('level', $message);
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'display_name.regex' => 'Use letters for the name we call you (spaces, hyphens and apostrophes are fine).',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['fullname' => 'full name', 'display_name' => 'name we call you', 'department_id' => 'department'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'fullname' => preg_replace('/\s+/', ' ', trim((string) $this->input('fullname'))),
            'display_name' => preg_replace('/\s+/', ' ', trim((string) $this->input('display_name'))) ?: null,
        ]);
    }
}
