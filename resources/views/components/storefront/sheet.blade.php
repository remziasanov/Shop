{{-- MB-12: bottom sheet for phones (sorting, saved addresses). Needs the state variable (default `open`) in the parent x-data. Focus is trapped while it is open, Escape / the close button / the back gesture (x-overlay) close it. --}}
@props(['id', 'title', 'model' => 'open'])
<div id="{{ $id }}" x-cloak x-show="{{ $model }}" x-overlay="{{ $model }}" x-trap.noscroll.inert="{{ $model }}" @keydown.escape.window="{{ $model }} = false"
     {{ $attributes->merge(['class' => 'fixed inset-0 z-50 flex items-end bg-black/50 md:hidden']) }} role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title" @click.self="{{ $model }} = false">
    <div class="shop-sheet-pad max-h-[85dvh] w-full overflow-y-auto rounded-t-2xl bg-white px-4 pt-3 shadow-xl">
        <div class="mb-2 flex items-center justify-between gap-3">
            <h2 id="{{ $id }}-title" class="text-lg font-semibold">{{ $title }}</h2>
            <button type="button" @click="{{ $model }} = false" class="flex h-11 w-11 items-center justify-center rounded text-slate-600 hover:bg-slate-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" aria-label="{{ __('mobile.sheet.close') }}">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        {{ $slot }}
    </div>
</div>
