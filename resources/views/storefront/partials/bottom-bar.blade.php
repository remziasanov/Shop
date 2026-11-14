{{-- SF-03, MB-03: bottom bar below 768 px. Items follow the header rules: no cart in the shop - no cart item, registration off - no «Войти», no catalog section - no catalog and search. Hidden on the checkout page (@section('no_bottom_nav')) and while a field has the focus (html[data-kb], resources/js/app.js). --}}
@php
    $item = 'flex flex-col items-center justify-center gap-0.5 px-1 py-1.5 text-xs hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary';
    $onCatalog = request()->routeIs('catalog.*', 'product.show');
    $onSearch = request()->routeIs('search');
    $inTelegram = \App\Support\TelegramMode::active();
    $onAccount = request()->routeIs('account', 'customer.*', 'tg.me', 'tg.about') && ! request()->routeIs('customer.login*', 'customer.register*');
@endphp
<nav class="shop-bottom-nav fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface md:hidden" aria-label="{{ __('storefront.bottom_bar.label') }}" data-bottom-nav>
    <ul class="grid grid-flow-col auto-cols-fr">
        @if ($catalogEnabled)
            <li>
                <a href="{{ route('catalog.index') }}" class="{{ $item }} {{ $onCatalog ? 'font-semibold text-primary' : 'text-ink-soft' }}" @if ($onCatalog) aria-current="page" @endif data-bar-item="catalog">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h7v7H4zM13 6h7v7h-7zM4 15h7v5H4zM13 15h7v5h-7z"/></svg>
                    <span>{{ __('storefront.bottom_bar.catalog') }}</span>
                </a>
            </li>
        @endif
        @if ($searchEnabled)
            <li>
                <a href="{{ route('search') }}" class="{{ $item }} {{ $onSearch ? 'font-semibold text-primary' : 'text-ink-soft' }}" @if ($onSearch) aria-current="page" @endif data-bar-item="search"
                   x-data @click.prevent="window.scrollTo({ top: 0 }); document.getElementById('header-search').focus()">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.3-4.3M10.5 18a7.5 7.5 0 100-15 7.5 7.5 0 000 15z"/></svg>
                    <span>{{ __('storefront.bottom_bar.search') }}</span>
                </a>
            </li>
        @endif
        @if ($cartEnabled)
            <livewire:storefront.mini-cart variant="bar" :active="request()->routeIs('cart')" />
        @endif
        {{-- TM-04, TM-06: in the Mini App the cabinet item always exists (a guest finds sign-in and «О магазине» there). --}}
        @if ($accountLinks || $inTelegram)
            <li>
                <a href="{{ $accountCustomerName !== null ? route('account') : ($inTelegram ? route('tg.me') : route('customer.login')) }}" class="{{ $item }} relative {{ $onAccount ? 'font-semibold text-primary' : 'text-ink-soft' }}" @if ($onAccount) aria-current="page" @endif data-account-bar data-bar-item="account">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5.5 20a6.5 6.5 0 0113 0M12 12a4 4 0 100-8 4 4 0 000 8z"/></svg>
                    <span>{{ $accountCustomerName !== null || $inTelegram ? __('mobile.bottom_nav.cabinet') : __('account.header.login') }}</span>
                    @if ($accountUnread > 0)
                        <span class="absolute right-1/4 top-0.5 min-w-5 rounded-full bg-primary px-1 text-center text-xs font-medium leading-5 text-on-primary" data-unread-badge><span class="sr-only">{{ __('account.header.unread') }}</span>{{ $accountUnread }}</span>
                    @endif
                </a>
            </li>
        @endif
    </ul>
</nav>
