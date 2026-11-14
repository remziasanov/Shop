{{-- Dialog for the product page forms (NF-31, MB-12: a bottom sheet on a phone, the back gesture closes it): focus is trapped inside while open (Alpine x-trap bundled with Livewire), Escape and the close button close it, focus returns to the trigger. Needs `open` in the parent x-data. --}}
@props(['id', 'title'])
<div x-cloak x-show="open" x-overlay="open" x-trap.noscroll.inert="open" @keydown.escape.window="open = false"
     class="fixed inset-0 z-50 flex items-end justify-center bg-scrim/50 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title">
    <div class="shop-sheet-pad max-h-[92dvh] w-full overflow-y-auto rounded-t-2xl bg-surface px-5 pt-5 shadow-xl sm:max-w-lg sm:rounded-card sm:pb-5" @click.outside="open = false">
        <div class="mb-4 flex items-start justify-between gap-3">
            <h2 id="{{ $id }}-title" class="text-xl font-semibold">{{ $title }}</h2>
            <button type="button" @click="open = false" class="flex h-11 w-11 flex-none items-center justify-center rounded-btn hover:bg-surface-3 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" aria-label="{{ __('storefront_sales.modal.close') }}">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        {{ $slot }}
    </div>
</div>
