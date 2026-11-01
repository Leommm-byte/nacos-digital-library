@php($editing = $department->exists)

<x-layouts.admin :title="$editing ? 'Edit '.$department->name : 'Add a department'">
    <x-page-header :title="$editing ? 'Edit department' : 'Add a department'" subtitle="Its name and logo appear at the top of its election pages."
        :back="$editing ? route('admin.departments.show', $department) : route('admin.departments.index')" :back-label="$editing ? $department->name : 'Departments'" />

    <form method="POST" action="{{ $editing ? route('admin.departments.update', $department) : route('admin.departments.store') }}" enctype="multipart/form-data" class="max-w-2xl space-y-6" novalidate>
        @csrf
        @if ($editing)
            @method('PUT')
        @endif

        <x-card class="space-y-5">
            <x-field name="name" label="Department name" :value="$department->name" required maxlength="150" placeholder="Food Science and Technology" />
            <x-field name="short_name" label="Short name" :value="$department->short_name" maxlength="30" placeholder="FST" hint="Optional, for tight spaces." />
            <x-field name="slug" label="Address" :value="$department->slug" maxlength="60" placeholder="food-science" :hint="'Its pages will be at '.url('/vote').'/… Leave empty to make one from the name.'" />

            <div>
                <x-file-input name="logo" label="Logo" icon="image" button="Choose a logo" accept="image/jpeg,image/png,image/webp" hint="Optional. JPG, PNG or WebP, up to 2 MB. Without one, the initials are shown." />
                @if ($editing && $department->logo_path)
                    <label class="mt-3 flex items-center gap-2.5 text-sm">
                        <input type="checkbox" name="remove_logo" value="1" class="size-4 accent-[var(--primary)]">
                        Remove the current logo
                    </label>
                @endif
            </div>
        </x-card>

        <x-card class="space-y-5">
            <div>
                <h2 class="text-base">Contact</h2>
                <p class="text-sm text-muted">Who you deal with at the department (for you only; never shown on its pages).</p>
            </div>
            <x-field name="contact_name" label="Contact person" :value="$department->contact_name" maxlength="150" />
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="contact_email" label="Email" type="email" :value="$department->contact_email" maxlength="255" />
                <x-field name="contact_phone" label="Phone" type="tel" :value="$department->contact_phone" maxlength="40" />
            </div>
            <div>
                <label for="notes" class="field-label">Notes</label>
                <textarea id="notes" name="notes" rows="3" maxlength="2000" class="field-input" placeholder="Payment, agreement, anything to remember">{{ old('notes', $department->notes) }}</textarea>
            </div>
        </x-card>

        <div class="flex flex-wrap gap-3">
            <x-button icon="check">{{ $editing ? 'Save' : 'Add department' }}</x-button>
            <x-button href="{{ $editing ? route('admin.departments.show', $department) : route('admin.departments.index') }}" variant="ghost">Cancel</x-button>
        </div>
    </form>
</x-layouts.admin>
