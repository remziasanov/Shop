{{-- MB-22: the iPhone bottom sheet «Поделиться → На экран Домой», once; closing hides it for 90 days (localStorage).
     Never shown in the installed app or in Telegram: the server leaves it out in Telegram mode, pwa.js checks the rest. --}}
@if (app(\App\Services\Pwa\PwaService::class)->installHintEnabled())
    <div x-data="pwaInstall" x-cloak x-show="sheet" x-transition.opacity data-pwa-ios-sheet
         class="fixed inset-x-0 bottom-0 z-50 border-t border-line bg-surface p-4 shadow-2xl"
         style="padding-bottom: calc(1rem + env(safe-area-inset-bottom, 0px))"
         role="dialog" aria-modal="false" aria-labelledby="pwa-ios-title" @keydown.escape.window="dismiss()">
        <div class="mx-auto flex max-w-md items-start gap-3">
            <div class="min-w-0 flex-1">
                <p id="pwa-ios-title" class="font-semibold">{{ __('pwa.install.ios_title') }}</p>
                <p class="mt-1 text-sm text-ink-mid">{{ __('pwa.install.ios_text') }}</p>
            </div>
            <button type="button" @click="dismiss()" class="-m-2 flex h-11 w-11 flex-none items-center justify-center rounded-btn text-muted hover:bg-surface-3 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
                    aria-label="{{ __('pwa.install.close') }}">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
    </div>
@endif
