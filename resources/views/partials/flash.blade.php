@if (session('status'))
    <x-alert type="success" class="animate-enter mb-6">{{ session('status') }}</x-alert>
@endif

@if (session('error'))
    <x-alert type="error" class="animate-enter mb-6">{{ session('error') }}</x-alert>
@endif
