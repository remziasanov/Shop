{{-- MB-23: «Назад» bar of the installed app (it has no browser button); shown only in display-mode: standalone and on
     inner pages. Without history (a page opened from outside) it leads to the home page. --}}
@if (app(\App\Services\Pwa\PwaService::class)->enabled() && ! request()->routeIs('home'))
    <style>.pwa-back{display:none}@media (display-mode: standalone){.pwa-back{display:block}}</style>
    <div class="pwa-back border-b border-slate-200 bg-white" data-pwa-back>
        <div class="mx-auto w-full max-w-7xl px-4">
            <button type="button" x-data @click="history.length > 1 ? history.back() : (window.location.href = '{{ route('home') }}')"
                    class="flex min-h-11 items-center gap-1 rounded pr-3 text-sm font-medium text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                {{ __('pwa.install.back') }}
            </button>
        </div>
    </div>
@endif
