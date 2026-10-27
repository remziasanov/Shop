<footer class="mt-8 border-t border-slate-200 bg-slate-50">
    <div class="mx-auto grid w-full max-w-7xl gap-6 px-4 py-8 text-sm text-slate-700 md:grid-cols-2 lg:grid-cols-4">
        <div>
            <p class="font-semibold">{{ $shopName }}</p>
            @if ($shopFooterText)
                <p class="mt-2 whitespace-pre-line">{{ $shopFooterText }}</p>
            @endif
            @if ($socialLinks !== [])
                <ul class="mt-3 flex flex-wrap gap-x-4 gap-y-1" aria-label="{{ __('storefront.footer.socials') }}">
                    @foreach ($socialLinks as $social)
                        <li>
                            <a href="{{ $social['url'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 rounded hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10 14a5 5 0 007 0l3-3a5 5 0 00-7-7l-1 1M14 10a5 5 0 00-7 0l-3 3a5 5 0 007 7l1-1"/></svg>
                                {{ $social['title'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if ($footerMenu !== [])
            <nav aria-labelledby="footer-menu">
                <h2 id="footer-menu" class="font-semibold">{{ __('storefront.footer.menu') }}</h2>
                <ul class="mt-2 space-y-1">
                    @foreach ($footerMenu as $item)
                        <li><a href="{{ $item['url'] }}" class="hover:text-primary hover:underline">{{ $item['label'] }}</a></li>
                    @endforeach
                </ul>
            </nav>
        @endif

        <section aria-labelledby="footer-contacts">
            <h2 id="footer-contacts" class="font-semibold">{{ __('storefront.footer.contacts') }}</h2>
            <ul class="mt-2 space-y-1">
                @foreach ($shopPhones as $phone)
                    <li><a href="tel:{{ preg_replace('/[^\d+]/', '', $phone) }}">{{ $phone }}</a></li>
                @endforeach
                @if ($shopEmail)
                    <li><a href="mailto:{{ $shopEmail }}">{{ $shopEmail }}</a></li>
                @endif
                @if ($shopAddress)
                    <li>{{ $shopAddress }}</li>
                @endif
            </ul>
            @if ($shopWorkingHours)
                <h2 id="footer-hours" class="mt-4 font-semibold">{{ __('storefront.footer.working_hours') }}</h2>
                <p class="mt-2 whitespace-pre-line">{{ $shopWorkingHours }}</p>
            @endif
        </section>

        @if ($sellerDetails || $footerPrivacyLink || $footerConsentLink)
            <section aria-labelledby="footer-legal">
                <h2 id="footer-legal" class="font-semibold">{{ __('storefront.footer.requisites') }}</h2>
                @if ($sellerDetails)
                    <p class="mt-2 whitespace-pre-line">{{ $sellerDetails }}</p>
                @endif
                @if ($footerPrivacyLink || $footerConsentLink)
                    <ul class="mt-3 space-y-1">
                        @foreach (array_filter([$footerPrivacyLink, $footerConsentLink]) as $legalLink)
                            <li><a href="{{ $legalLink['url'] }}" class="hover:text-primary hover:underline">{{ $legalLink['label'] }}</a></li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif
    </div>
    <div class="border-t border-slate-200 py-3 text-center text-xs text-slate-500">
        {{ __('storefront.footer.copyright', ['year' => now()->year, 'name' => $shopName]) }}
    </div>
</footer>
