{{-- Temporary home page. Replaced by the dashboard in the dashboard PR. --}}
<x-layouts.app>
    <section class="animate-enter relative overflow-hidden rounded-xl bg-green-800 px-6 py-10 text-white sm:px-10 sm:py-14">
        <div aria-hidden="true" class="absolute -top-16 -right-16 size-56 rounded-full bg-yellow-400/20"></div>
        <div aria-hidden="true" class="absolute -bottom-20 right-24 size-40 rounded-full bg-green-500/30"></div>

        <div class="relative max-w-2xl">
            <x-badge variant="accent" class="mb-4">{{ config('app.name') }}</x-badge>
            <h1 class="text-3xl text-white sm:text-4xl">Your library, elections and student services in one place.</h1>
            <p class="mt-4 text-lg text-green-100">Find books and past questions, follow announcements and vote in NACOS elections.</p>
        </div>
    </section>

    <section aria-labelledby="features" class="mt-10">
        <h2 id="features" class="text-xl">What's coming</h2>
        <ul class="stagger mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['library-big', 'Library', 'Books and past questions for your department, readable on any phone.'],
                ['vote', 'Elections', 'Secret ballots with live, public turnout and results.'],
                ['bookmark', 'Saved books', 'Keep your place and pick up where you left off.'],
                ['upload', 'Uploads', 'Share materials with your class, reviewed by course reps.'],
            ] as [$icon, $name, $text])
                <li class="card p-5">
                    <span class="inline-grid size-10 place-items-center rounded-md bg-primary-soft text-link">
                        <x-icon :name="$icon" />
                    </span>
                    <h3 class="mt-4 text-base">{{ $name }}</h3>
                    <p class="mt-1 text-sm text-muted">{{ $text }}</p>
                </li>
            @endforeach
        </ul>
    </section>

    @isset($status)
        <section aria-labelledby="stack" class="mt-10" data-reveal>
            <x-card>
                <h2 id="stack" class="text-base">Local stack status</h2>
                <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-6 gap-y-1.5 text-sm">
                    @foreach ($status as $label => $value)
                        <dt class="text-muted">{{ $label }}</dt>
                        <dd class="font-medium">{{ $value }}</dd>
                    @endforeach
                </dl>
                @if (Route::has('styleguide'))
                    <p class="mt-4 text-sm"><a href="{{ route('styleguide') }}" class="link">Open the style guide</a></p>
                @endif
            </x-card>
        </section>
    @endisset
</x-layouts.app>
