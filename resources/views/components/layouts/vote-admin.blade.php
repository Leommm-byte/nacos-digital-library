{{--
    A department admin's pages: the department's layout with its admin
    sections beside the page (tabs on phones), like the NACOS admin area.
--}}
@props(['title' => null])

@php
    $sections = [
        ['route' => 'vote.admin.elections', 'match' => 'vote.admin.elections*', 'label' => 'Elections', 'icon' => 'vote'],
        ['route' => 'vote.admin.roll', 'match' => 'vote.admin.roll*', 'label' => 'Nominal roll', 'icon' => 'clipboard-list'],
        ['route' => 'vote.admin.voters', 'match' => 'vote.admin.voters*', 'label' => 'Voters', 'icon' => 'users'],
        ['route' => 'vote.admin.guide', 'match' => 'vote.admin.guide', 'label' => 'Guide', 'icon' => 'book-open'],
    ];
@endphp

<x-layouts.vote :title="$title ? $title.' · Manage' : 'Manage'">
    <div class="admin">
        <nav class="admin-nav" aria-label="Manage">
            <p class="admin-nav-title">Manage</p>
            <ul>
                @foreach ($sections as $item)
                    @php($active = request()->routeIs($item['match']))
                    <li>
                        <a href="{{ route($item['route']) }}" @class(['admin-nav-link', 'is-active' => $active]) @if ($active) aria-current="page" @endif>
                            <x-icon :name="$item['icon']" /> {{ $item['label'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>
        <div class="min-w-0">
            @if (! auth('voter')->check())
                <x-alert type="info" class="mb-6">You're managing {{ $organization->name }} as a NACOS admin. Changes you make here are the same as the department's admins making them. <a href="{{ route('admin.departments.show', $organization) }}" class="link">Back to Admin → Departments</a></x-alert>
            @endif
            {{ $slot }}
        </div>
    </div>
</x-layouts.vote>
