<nav aria-label="Main" class="tabbar md:hidden">
    @foreach ($nav as $item)
        <a href="{{ route($item['route']) }}" class="tabbar-link" @if ($item['active']) aria-current="page" @endif>
            <x-icon :name="$item['icon']" />
            <span>{{ $item['label'] }}</span>
        </a>
    @endforeach
</nav>
