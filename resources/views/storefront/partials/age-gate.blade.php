{{-- TZ 16: age gate overlay (AG-04, AG-07, AG-08, AG-10). $gate: App\Services\Age\AgeGate.
     The markup is the same for every visitor: CSS hides it when html[data-age-ok] already satisfies data-age-need (an inline script
     sets that from the shop_age cookie before the first paint). Without JavaScript it is a plain form. It is an overlay on a full HTML page,
     so robots read the page itself (AG-09). The dialog cannot be dismissed without an answer. --}}
@php($overlay = $gate->overlay(request()))
@if ($overlay)
    @php($level = $overlay['level'])
    <div class="age-gate" data-age-need="{{ $level->value }}" role="dialog" aria-modal="true" aria-labelledby="age-gate-title" aria-describedby="age-gate-text"
         x-data="ageGate({{ $level->minAge() }})" x-trap.noscroll.inert="active" @keydown.escape.prevent>
        <div class="w-full max-w-md rounded-lg bg-white p-6 text-center shadow-xl">
            <p class="mb-3 inline-block rounded bg-red-700 px-3 py-1 text-lg font-bold text-white" aria-hidden="true">{{ $level->badge() }}</p>
            <h2 id="age-gate-title" class="text-xl font-semibold">{{ $overlay['title'] }}</h2>

            @if ($overlay['declined'])
                <p id="age-gate-text" class="mt-3 text-slate-700" role="alert">{{ $overlay['message'] }}</p>
                <form method="post" action="{{ route('age-gate.confirm', [], false) }}" class="mt-6">
                    @csrf
                    <input type="hidden" name="level" value="{{ $level->value }}">
                    <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
                    <button type="submit" class="w-full rounded bg-primary px-4 py-3 font-medium text-white hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('age_gate.already_of_age', ['age' => $level->minAge()]) }}</button>
                </form>
            @else
                <p id="age-gate-text" class="mt-3 text-slate-700">{{ $overlay['text'] }}</p>
                <form method="post" action="{{ route('age-gate.confirm', [], false) }}" class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-center">
                    @csrf
                    <input type="hidden" name="level" value="{{ $level->value }}">
                    <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
                    <button type="submit" class="rounded bg-primary px-5 py-3 font-medium text-white hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ $overlay['yes'] }}</button>
                    <a href="{{ route('age-gate.decline', ['level' => $level->value], false) }}" rel="nofollow" class="rounded border border-slate-300 px-5 py-3 font-medium hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ $overlay['no'] }}</a>
                </form>
            @endif
            <p class="mt-4 text-xs text-slate-500">{{ __('age_gate.good_faith') }}</p>
        </div>
    </div>
@elseif ($notice = $gate->declinedNotice(request()))
    {{-- AG-08: «restricted» mode sends a refusal to the home page, which has no overlay: show the polite message. --}}
    <div x-data="{ open: true }" x-show="open" role="status" class="fixed inset-x-0 top-0 z-50 flex items-start gap-3 bg-slate-900 px-4 py-3 text-sm text-white">
        <p class="flex-1">{{ $notice }}</p>
        <button type="button" @click="open = false" class="rounded p-1 hover:bg-white/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-white" aria-label="{{ __('age_gate.close_notice') }}">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
    </div>
@endif
