{{-- All validation and action errors of the page in one announced block; `status` flash messages next to it. --}}
@if (session('status'))
    <p class="mb-4 rounded-btn border border-success-line bg-success-wash px-3 py-2 text-sm text-success-deep" role="status">{{ session('status') }}</p>
@endif
@if (session('access_message'))
    <p class="mb-4 rounded-btn border border-warn-line bg-warn-wash px-3 py-2 text-sm text-warn-ink" role="status">{{ session('access_message') }}</p>
@endif
<div aria-live="assertive" role="alert">
    @if ($errors->any())
        <ul class="mb-4 space-y-1 rounded-btn border border-danger-line bg-danger-wash px-3 py-2 text-sm text-danger-ink">
            @foreach (array_unique($errors->all()) as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    @endif
</div>
