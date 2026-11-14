{{-- SF-08: cookie consent; not rendered when the cookie is present or the banner is disabled. --}}
@if ($cookieBannerShow ?? false)
    <div x-data="{ visible: true, accept() {
            document.cookie = 'cookie_consent=1; Max-Age=31536000; Path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
            this.visible = false;
         } }"
         x-show="visible" data-cookie-banner role="dialog" aria-label="{{ __('seo.cookie.label') }}"
         class="shop-above-nav-flush fixed inset-x-0 bottom-0 z-40 border-t border-line bg-surface p-4 shadow-lg">
        <div class="mx-auto flex max-w-7xl flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-ink-soft">
                {{ $cookieBannerText }}
                @if ($cookiePolicyUrl)
                    <a href="{{ $cookiePolicyUrl }}" class="text-primary underline">{{ __('seo.cookie.policy') }}</a>
                @endif
            </p>
            <button type="button" @click="accept()" class="flex-none rounded-btn bg-primary px-5 py-2 text-sm font-medium text-on-primary hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('seo.cookie.accept') }}</button>
        </div>
    </div>
@endif
