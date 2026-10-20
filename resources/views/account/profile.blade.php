<x-layouts.app title="Profile">
    <x-page-header title="Your profile" subtitle="Keep your class details up to date so you see the right books and elections." />

    <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-[22rem_minmax(0,1fr)]">
        {{-- Live preview: resources/js/preview.js updates it as the form changes. --}}
        <x-card class="profile-card animate-enter lg:sticky lg:top-[calc(var(--header-h)+1.5rem)]">
            <div class="flex items-center gap-4">
                <span class="avatar size-14 text-xl" data-preview-initial="fullname" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->fullname, 0, 1)) }}</span>
                <div class="min-w-0">
                    <p class="truncate font-display text-lg font-bold" data-preview-text="fullname">{{ $user->fullname }}</p>
                    <p class="truncate text-sm text-muted">{{ $user->matric_number }}</p>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                <x-badge :variant="$user->roleLabel() === 'Graduate' ? 'accent' : 'primary'">{{ $user->roleLabel() }}</x-badge>
                @if ($user->isGraduated() && $user->roleLabel() !== 'Graduate')
                    <x-badge variant="accent">Graduate</x-badge>
                @endif
                <x-badge data-preview-text="level">{{ $user->level->label() }}</x-badge>
                @if ($user->arm)
                    <x-badge>{{ \App\Support\Classes\Arms::short($user->arm) }}</x-badge>
                @endif
                <x-badge data-preview-text="programme">{{ $user->programme->label() }}</x-badge>
            </div>

            <dl class="mt-5 space-y-3 border-t border-border pt-4 text-sm">
                <div>
                    <dt class="text-muted">Department</dt>
                    <dd class="font-medium" data-preview-text="department_id">{{ $user->department->name }}</dd>
                </div>
                <div>
                    <dt class="text-muted">Member since</dt>
                    <dd class="font-medium">{{ $user->created_at->timezone(config('app.display_timezone'))->format('j F Y') }}</dd>
                </div>
                <div>
                    <dt class="text-muted">Previous login</dt>
                    <dd class="font-medium">
                        @if ($previousLoginAt)
                            {{ $previousLoginAt->timezone(config('app.display_timezone'))->format('j M Y, g:i a') }}
                            @if ($previousLoginIp)
                                <span class="text-muted">from {{ $previousLoginIp }}</span>
                            @endif
                        @else
                            This is your first login
                        @endif
                    </dd>
                </div>
            </dl>
            <p class="mt-4 text-xs text-muted">Don't recognise the previous login? <a href="{{ route('settings') }}" class="link">Change your password</a>.</p>
        </x-card>

        <x-card class="animate-enter">
            <h2 class="text-lg">Edit details</h2>
            <p class="mt-1 text-sm text-muted">Changes show in the card as you type.</p>

            <form method="POST" action="{{ route('profile.update') }}" class="mt-5 space-y-4" novalidate>
                @csrf
                @method('PUT')

                <x-field name="fullname" label="Full name" autocomplete="name" :value="$user->fullname" data-preview="fullname" hint="As on your school records. Used on official lists." required />
                <x-field name="display_name" label="What should we call you? (optional)" autocomplete="nickname" maxlength="30" :value="$user->display_name" hint="Used in greetings. Leave it empty to use your first name ({{ $user->firstName() }})." />

                <div>
                    <span class="field-label">Matric number</span>
                    <p class="field-input flex items-center bg-surface-2 text-muted">{{ $user->matric_number }}</p>
                    <p class="mt-1.5 text-sm text-muted">Your matric number can't be changed. If it's wrong, ask an admin. @if ($user->arm) Your course, {{ \App\Support\Classes\Arms::name($user->arm) }} ({{ \App\Support\Classes\Arms::short($user->arm) }}), comes from it.@endif</p>
                </div>

                <x-select name="department_id" label="Department" :options="$departments" :value="$user->department_id" data-preview="department_id" :disabled="$classLocked" required />

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-select name="level" label="Level" :options="$levels" :value="$user->level->value" data-preview="level" :disabled="$classLocked || $graduated" required />
                    <x-select name="programme" label="Programme" :options="$programmes" :value="$user->programme->value" data-preview="programme" :disabled="$graduated" required />
                </div>

                @if ($graduated)
                    @unless ($classLocked)
                        <input type="hidden" name="level" value="{{ $user->level->value }}">
                    @endunless
                    <input type="hidden" name="programme" value="{{ $user->programme->value }}">
                    <p class="text-sm text-muted">You graduated from {{ $user->classLabel() }}. Your class can't change now; if that's a mistake, ask an admin.</p>
                @endif

                @if ($classLocked)
                    {{-- Disabled fields aren't submitted; send the current values. --}}
                    <input type="hidden" name="department_id" value="{{ $user->department_id }}">
                    <input type="hidden" name="level" value="{{ $user->level->value }}">
                    <p class="text-sm text-muted">As a {{ strtolower($user->roleLabel()) }}, your department and level can only be changed by an admin.</p>
                @endif

                <div class="flex flex-wrap gap-3 pt-2">
                    <x-button>Save changes</x-button>
                    <x-button href="{{ route('settings') }}" variant="ghost" icon="settings">Account settings</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-layouts.app>
