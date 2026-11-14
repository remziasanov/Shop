{{-- MB-08: a block that folds on a phone (always open from 768 px). `open` = the state at first render; a field with an error inside opens it (resources/js/app.js). wire:ignore.self: Livewire leaves the Alpine state and classes alone. --}}
@props(['id', 'title', 'open' => false, 'tag' => 'h2', 'required' => false])
<div x-data="collapse(@js((bool) $open))" wire:ignore.self data-collapse {{ $attributes }}>
    <{{ $tag }} id="{{ $id }}-title" class="text-lg font-semibold">
        <button type="button" class="flex w-full items-center justify-between gap-3 text-left md:hidden" :aria-expanded="open.toString()" aria-expanded="{{ $open ? 'true' : 'false' }}" aria-controls="{{ $id }}" @click="open = ! open">
            <span>{{ $title }}@if ($required) <span class="text-danger" aria-hidden="true">*</span>@endif</span>
            <svg class="h-5 w-5 flex-none transition-transform" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
        </button>
        <span class="max-md:hidden">{{ $title }}@if ($required) <span class="text-danger" aria-hidden="true">*</span>@endif</span>
    </{{ $tag }}>
    <div id="{{ $id }}" role="group" aria-labelledby="{{ $id }}-title" wire:ignore.self class="mt-3 {{ $open ? '' : 'max-md:hidden' }}" :class="{ 'max-md:hidden': ! open }">
        {{ $slot }}
    </div>
</div>
