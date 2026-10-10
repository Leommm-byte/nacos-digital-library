<x-layouts.app description="Books, past questions, elections and student services for NACOS YabaTech.">
    <section class="hero animate-enter">
        <div class="hero-pattern" aria-hidden="true"></div>
        <div class="relative grid items-center gap-10 lg:grid-cols-[1.15fr_1fr]">
            <div>
                <p class="hero-eyebrow">NACOS · Yaba College of Technology</p>
                <h1 class="hero-title">Your course books, past questions and NACOS life, in one place.</h1>
                <p class="hero-lead">Read on any phone, save what you need for exams, and vote in NACOS elections with a secret ballot.</p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <x-button href="{{ route('signup') }}" variant="accent" icon="user-plus">Create your account</x-button>
                    <x-button href="{{ route('login') }}" variant="on-dark" icon="log-in">Log in</x-button>
                </div>
            </div>

            <div class="hero-shelf" aria-hidden="true">
                <div class="hero-book"><span>Data Structures</span></div>
                <div class="hero-book"><span>Past Questions ND1</span></div>
                <div class="hero-book"><span>Computer Networks</span></div>
            </div>
        </div>
    </section>

    @include('partials.install-banner')

    <section class="mt-12 md:mt-16" aria-labelledby="features">
        <h2 id="features" class="sr-only">What you get</h2>
        <ul class="stagger grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['library-big', 'green', 'Library', 'Course books and past questions for your department and level.'],
                ['bookmark', 'yellow', 'Saved books', 'Keep what you need for exams one tap away.'],
                ['vote', 'blue', 'Elections', 'Secret ballots with live, public turnout and results.'],
                ['shield-check', 'violet', 'Secure account', 'Matric-number login with optional two-step verification.'],
            ] as [$icon, $tone, $name, $text])
                <li class="card flex items-start gap-4 p-5 sm:flex-col">
                    <x-icon-tile :name="$icon" :tone="$tone" />
                    <div>
                        <h3 class="text-base">{{ $name }}</h3>
                        <p class="mt-1 text-sm text-muted">{{ $text }}</p>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>
</x-layouts.app>
