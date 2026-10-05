{{-- Successes become a toast in the app layout ($toast); errors stay as banners. --}}
@if (session('status') && empty($toast))
    <x-alert type="success" class="animate-enter mb-6">{{ session('status') }}</x-alert>
@endif

@if (session('error'))
    <x-alert type="error" class="animate-enter mb-6">{{ session('error') }}</x-alert>
@endif
