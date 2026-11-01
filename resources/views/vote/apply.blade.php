<x-layouts.app title="Ask for your department" description="Ask NACOS YabaTech to run your department's elections.">
    <x-page-header title="Run your department's elections with NACOS" subtitle="Your department gets its own election space: its name and logo, its nominal roll, and students who register to vote with a secret ballot and live results. Tell us who you are; a NACOS admin will get back to you."
        :back="route('vote.directory')" back-label="Department elections" />

    <form method="POST" action="{{ route('vote.apply.store') }}" class="max-w-2xl space-y-6" novalidate>
        @csrf
        <x-card class="space-y-5">
            <x-field name="department_name" label="Department or association" required maxlength="150" placeholder="Food Science and Technology" />
            <x-field name="contact_name" label="Your name" autocomplete="name" required maxlength="150" />
            <x-field name="contact_role" label="Your role" maxlength="100" placeholder="President, Electoral Committee Chair…" />
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="contact_email" label="Email" type="email" autocomplete="email" required maxlength="255" />
                <x-field name="contact_phone" label="Phone" type="tel" autocomplete="tel" maxlength="40" />
            </div>
            <div>
                <label for="message" class="field-label">Anything else</label>
                <textarea id="message" name="message" rows="4" maxlength="2000" class="field-input" placeholder="When is your election? About how many students?">{{ old('message') }}</textarea>
            </div>
            {{-- Left empty by people; bots fill it in. --}}
            <div class="sr-only" aria-hidden="true">
                <label for="website">Website</label>
                <input id="website" name="website" type="text" tabindex="-1" autocomplete="off">
            </div>
        </x-card>

        <x-button icon="send">Send the request</x-button>
    </form>
</x-layouts.app>
