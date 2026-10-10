{{-- One quiet line: who runs the site. The pages are reached from the header
     and, on phones, the tab bar, so the footer has no links of its own and
     is hidden on phones that show the tab bar. --}}
<footer class="site-footer">
    {{-- Left-aligned: the assistant's Ask button floats bottom right. --}}
    <div class="container-page flex flex-col gap-x-6 gap-y-2 sm:flex-row sm:flex-wrap sm:items-center">
        <p class="flex items-center gap-2">
            <img src="{{ asset('images/logo-96.webp') }}" alt="" width="20" height="20" class="size-5" loading="lazy" decoding="async">
            <span><span class="font-medium text-fg">NACOS YabaTech</span> · Yaba College of Technology</span>
        </p>
        <p class="text-xs">&copy; {{ now()->year }} · Built by students, for students.</p>
    </div>
</footer>
