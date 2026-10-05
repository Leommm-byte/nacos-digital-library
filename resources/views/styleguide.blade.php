{{-- Not routed in production. --}}
<x-layouts.app title="Style guide">
    <div class="animate-enter">
        <h1 class="text-3xl">Style guide</h1>
        <p class="mt-2 text-muted">Tokens and components from <code>resources/css</code> and <code>resources/views/components</code>. Use the theme button in the header to check dark mode.</p>
    </div>

    <section class="mt-10" aria-labelledby="sg-colours">
        <h2 id="sg-colours" class="text-xl">Colours</h2>
        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
            @foreach ([
                'bg-bg' => '--bg', 'bg-surface' => '--surface', 'bg-surface-2' => '--surface-2', 'bg-border' => '--border',
                'bg-fg' => '--fg', 'bg-muted' => '--muted', 'bg-primary' => '--primary', 'bg-primary-soft' => '--primary-soft',
                'bg-accent' => '--accent', 'bg-accent-soft' => '--accent-soft', 'bg-danger' => '--danger', 'bg-info' => '--info',
            ] as $class => $token)
                <div class="card overflow-hidden">
                    <div class="{{ $class }} h-14 border-b border-border"></div>
                    <p class="px-3 py-2 font-mono text-xs">{{ $token }}</p>
                </div>
            @endforeach
        </div>
        <div class="mt-4 flex overflow-hidden rounded-lg">
            @foreach (['bg-green-50', 'bg-green-100', 'bg-green-200', 'bg-green-300', 'bg-green-400', 'bg-green-500', 'bg-green-600', 'bg-green-700', 'bg-green-800', 'bg-green-900', 'bg-green-950'] as $class)
                <div class="{{ $class }} h-10 flex-1" title="{{ $class }}"></div>
            @endforeach
        </div>
        <div class="mt-2 flex overflow-hidden rounded-lg">
            @foreach (['bg-yellow-100', 'bg-yellow-200', 'bg-yellow-300', 'bg-yellow-400', 'bg-yellow-500', 'bg-yellow-600'] as $class)
                <div class="{{ $class }} h-10 flex-1" title="{{ $class }}"></div>
            @endforeach
        </div>
    </section>

    <section class="mt-10" aria-labelledby="sg-type" data-reveal>
        <h2 id="sg-type" class="text-xl">Type</h2>
        <x-card class="mt-4 space-y-3">
            <p class="font-display text-4xl font-extrabold tracking-tight">Plus Jakarta Sans, headings</p>
            <p class="text-2xl font-bold font-display">The quick brown fox jumps over the lazy dog</p>
            <p>Inter for body text. Students can read books, past questions and announcements on any phone, on any connection.</p>
            <p class="text-sm text-muted">Muted small text for hints and metadata. <a href="#" class="link">An inline link</a>.</p>
        </x-card>
    </section>

    <section class="mt-10" aria-labelledby="sg-buttons" data-reveal>
        <h2 id="sg-buttons" class="text-xl">Buttons and badges</h2>
        <x-card class="mt-4 space-y-4">
            <div class="flex flex-wrap gap-3">
                <x-button type="button" icon="book-open">Primary</x-button>
                <x-button type="button" variant="accent">Accent</x-button>
                <x-button type="button" variant="secondary">Secondary</x-button>
                <x-button type="button" variant="ghost">Ghost</x-button>
                <x-button type="button" variant="danger">Danger</x-button>
                <x-button type="button" disabled>Disabled</x-button>
                <x-button type="button" size="sm" variant="secondary" icon="upload">Small</x-button>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-badge>Neutral</x-badge>
                <x-badge variant="primary">Approved</x-badge>
                <x-badge variant="accent">New</x-badge>
                <x-badge variant="danger">Rejected</x-badge>
            </div>
        </x-card>
    </section>

    <section class="mt-10" aria-labelledby="sg-alerts" data-reveal>
        <h2 id="sg-alerts" class="text-xl">Alerts</h2>
        <div class="mt-4 space-y-3">
            <x-alert type="info">Voting opens on Monday at 9:00.</x-alert>
            <x-alert type="success">Your upload was sent for review.</x-alert>
            <x-alert type="warning">Add an email address so you can reset your password.</x-alert>
            <x-alert type="error">That matric number or password is incorrect.</x-alert>
        </div>
    </section>

    <section class="mt-10" aria-labelledby="sg-forms" data-reveal>
        <h2 id="sg-forms" class="text-xl">Form fields</h2>
        <x-card class="mt-4 grid gap-4 sm:grid-cols-2">
            <x-field name="matric_number" label="Matric number" placeholder="F/HD/24/0000001" hint="As printed on your ID card." />
            <x-field name="password" label="Password" type="password" autocomplete="off" />
        </x-card>
    </section>

    <section class="mt-10" aria-labelledby="sg-icons" data-reveal>
        <h2 id="sg-icons" class="text-xl">Icons</h2>
        <x-card class="mt-4">
            <ul class="grid grid-cols-3 gap-4 sm:grid-cols-6 lg:grid-cols-9">
                @foreach (collect(glob(resource_path('icons/*.svg')))->map(fn ($path) => basename($path, '.svg')) as $icon)
                    <li class="flex flex-col items-center gap-1.5 text-center text-xs text-muted">
                        <x-icon :name="$icon" class="size-6 text-fg" />
                        {{ $icon }}
                    </li>
                @endforeach
            </ul>
        </x-card>
    </section>

    <section class="mt-10" aria-labelledby="sg-motion">
        <h2 id="sg-motion" class="text-xl">Motion</h2>
        <p class="mt-1 text-sm text-muted"><code>.stagger</code> children enter in turn; sections above use <code>data-reveal</code>. Both are instant with reduced motion.</p>
        <div class="stagger mt-4 grid grid-cols-3 gap-3 sm:grid-cols-6">
            @for ($i = 1; $i <= 6; $i++)
                <div class="card grid h-16 place-items-center font-display font-bold">{{ $i }}</div>
            @endfor
        </div>
        <div class="mt-4 space-y-2" aria-hidden="true">
            <div class="skeleton h-4 w-2/3"></div>
            <div class="skeleton h-4 w-1/2"></div>
        </div>
    </section>
</x-layouts.app>
