{{-- MB-22: «Установить приложение» for Android / desktop (beforeinstallprompt). Include it in the mobile menu and the
     cabinet; it renders nothing unless the app and its hint are on, and stays hidden until the browser offers the install. --}}
@if (app(\App\Services\Pwa\PwaService::class)->installHintEnabled())
    <div x-data="pwaInstall" x-cloak x-show="canInstall" data-pwa-install-item>
        <button type="button" @click="install()"
                class="{{ $class ?? 'flex min-h-11 w-full items-center gap-2 rounded-btn px-3 py-2 text-left text-sm font-medium hover:bg-surface-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary' }}">
            <svg class="h-5 w-5 flex-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0l-4-4m4 4l4-4M5 20h14"/></svg>
            {{ __('pwa.install.button') }}
        </button>
    </div>
@endif
