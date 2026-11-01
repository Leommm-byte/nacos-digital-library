{{-- The department's logo, or its initials when it has none. Expects $organization and $size: 'sm' (header) or 'lg'. --}}
@php($large = ($size ?? 'sm') === 'lg')
@if ($organization->logo_path)
    <img src="{{ route('vote.logo', ['organization' => $organization, 'v' => substr(sha1($organization->logo_path), 0, 10)]) }}" alt="" width="{{ $large ? 64 : 36 }}" height="{{ $large ? 64 : 36 }}" @class(['vote-logo', 'vote-logo-lg' => $large]) decoding="async">
@else
    <span @class(['vote-logo vote-logo-initials', 'vote-logo-lg' => $large]) aria-hidden="true">{{ $organization->short_name && mb_strlen($organization->short_name) <= 4 ? mb_strtoupper($organization->short_name) : \Illuminate\Support\Str::of($organization->name)->explode(' ')->filter(fn ($word) => ! in_array(strtolower($word), ['and', 'of', 'the', '&'], true))->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))->take(3)->implode('') }}</span>
@endif
