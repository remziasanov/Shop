{{-- All validation and action errors of the page in one announced block; `status` flash messages next to it. --}}
@if (session('status'))
    <p class="mb-4 rounded border border-green-300 bg-green-50 px-3 py-2 text-sm text-green-900" role="status">{{ session('status') }}</p>
@endif
@if (session('access_message'))
    <p class="mb-4 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900" role="status">{{ session('access_message') }}</p>
@endif
<div aria-live="assertive" role="alert">
    @if ($errors->any())
        <ul class="mb-4 space-y-1 rounded border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800">
            @foreach (array_unique($errors->all()) as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    @endif
</div>
