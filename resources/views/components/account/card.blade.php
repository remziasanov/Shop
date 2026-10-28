{{-- Narrow centered card of the sign-in, registration and code pages. --}}
@props(['title', 'lead' => null])
<div class="mx-auto w-full max-w-md py-6">
    <h1 class="text-2xl font-bold md:text-3xl">{{ $title }}</h1>
    @if ($lead)
        <p class="mt-2 text-slate-600">{{ $lead }}</p>
    @endif
    <div class="mt-6">
        <x-account.errors />
        {{ $slot }}
    </div>
</div>
