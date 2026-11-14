{{-- Form level error (guard, sales rule). aria-live: announced when it appears. --}}
<div aria-live="assertive" role="alert">
    @error('form')
        <p class="rounded-btn border border-danger-line bg-danger-wash px-3 py-2 text-sm text-danger-ink">{{ $message }}</p>
    @enderror
</div>
