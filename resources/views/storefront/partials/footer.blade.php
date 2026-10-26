<footer class="mt-8 border-t border-slate-200 bg-slate-50">
    <div class="mx-auto grid w-full max-w-7xl gap-6 px-4 py-8 text-sm text-slate-700 md:grid-cols-3">
        <div>
            <p class="font-semibold">{{ $shopName }}</p>
            @if ($shopFooterText)
                <p class="mt-2 whitespace-pre-line">{{ $shopFooterText }}</p>
            @endif
        </div>

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
        </section>

        @if ($shopWorkingHours)
            <section aria-labelledby="footer-hours">
                <h2 id="footer-hours" class="font-semibold">{{ __('storefront.footer.working_hours') }}</h2>
                <p class="mt-2 whitespace-pre-line">{{ $shopWorkingHours }}</p>
            </section>
        @endif
    </div>
    <div class="border-t border-slate-200 py-3 text-center text-xs text-slate-500">
        {{ __('storefront.footer.copyright', ['year' => now()->year, 'name' => $shopName]) }}
    </div>
</footer>
