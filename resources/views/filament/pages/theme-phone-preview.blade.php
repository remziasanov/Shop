<x-filament-panels::page>
    <style>
        .shop-tp-hint { margin: 0; font-size: .875rem; color: var(--gray-500); }
        .dark .shop-tp-hint { color: var(--gray-400); }
        .shop-tp-wrap { display: flex; justify-content: center; padding: 1rem 0; overflow-x: auto; }
        .shop-tp-device { flex: none; box-sizing: content-box; width: 390px; height: 844px; border: 10px solid #1f2937; border-radius: 2.5rem;
            background: #1f2937; box-shadow: 0 20px 40px rgba(0, 0, 0, .25); overflow: hidden; }
        .shop-tp-frame { display: block; width: 390px; height: 844px; border: 0; border-radius: 1.75rem; background: #fff; }
    </style>
    <p class="shop-tp-hint">{{ __('themes.phone.hint') }}</p>
    <div class="shop-tp-wrap">
        <div class="shop-tp-device">
            <iframe
                src="{{ route('home') }}"
                title="{{ __('themes.phone.frame_title') }}"
                width="390"
                height="844"
                class="shop-tp-frame"
                data-theme-phone-frame
            ></iframe>
        </div>
    </div>
</x-filament-panels::page>
