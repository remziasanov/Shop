{{-- Form level error (guard, sales rule). aria-live: announced when it appears. --}}
<div aria-live="assertive" role="alert">
    @error('form')
        <p class="rounded border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>
    @enderror
</div>
