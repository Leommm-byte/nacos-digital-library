{{-- The ballot form, for NACOS's elections and departments' alike. Expects $election (with positions.candidates) and $action. --}}
<form method="POST" action="{{ $action }}" class="space-y-5" data-ballot
    data-confirm="Cast your vote? You can't change it afterwards.">
    @csrf
    @error('ballot')
        <x-alert type="error">{{ $message }}</x-alert>
    @enderror

    <div class="ballot-intro">
        <x-icon-tile name="vote" tone="green" size="sm" />
        <p class="text-sm text-muted">Choose one candidate for each position, or leave a position out. Your ballot is secret: we keep a record that you voted, never who you chose.</p>
    </div>

    @foreach ($election->positions as $position)
        @php($chosen = (string) old("choices.{$position->id}", ''))
        <fieldset class="ballot-position">
            <legend class="ballot-position-title"><span class="upload-step"><span>{{ $loop->iteration }}</span></span> {{ $position->title }}</legend>
            <div class="ballot-options">
                @foreach ($position->candidates as $candidate)
                    <label class="ballot-option">
                        <input type="radio" name="choices[{{ $position->id }}]" value="{{ $candidate->id }}" class="sr-only" @checked($chosen === (string) $candidate->id) data-ballot-choice>
                        <x-candidate-avatar :name="$candidate->name" :photo="$candidate->photoUrl($election)" size="lg" />
                        <span class="min-w-0 flex-1">
                            <span class="ballot-option-name">{{ $candidate->name }}</span>
                            @if ($candidate->manifesto)
                                <span class="ballot-option-text">{{ $candidate->manifesto }}</span>
                            @endif
                        </span>
                        <span class="ballot-option-check" aria-hidden="true"><x-icon name="circle-check" /></span>
                    </label>
                @endforeach
                <label class="ballot-option ballot-option-skip">
                    <input type="radio" name="choices[{{ $position->id }}]" value="" class="sr-only" @checked($chosen === '')>
                    <span class="ballot-option-name">No vote for {{ $position->title }}</span>
                </label>
            </div>
        </fieldset>
    @endforeach

    <div class="ballot-submit">
        <p class="text-sm" data-ballot-summary aria-live="polite">{{ $election->positions->count() }} {{ \Illuminate\Support\Str::plural('position', $election->positions->count()) }} on this ballot.</p>
        <x-button icon="vote">Cast my vote</x-button>
    </div>
</form>
