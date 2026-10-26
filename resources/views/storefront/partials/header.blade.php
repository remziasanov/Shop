<header class="border-b border-slate-200 bg-white">
    <div class="mx-auto flex w-full max-w-7xl flex-wrap items-center gap-3 px-4 py-3">
        <button type="button" class="rounded p-2 hover:bg-slate-100 md:hidden" aria-label="{{ __('storefront.header.menu') }}">
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>

        <a href="{{ route('home') }}" class="flex items-center gap-2 text-lg font-semibold text-primary" aria-label="{{ __('storefront.header.home') }}">
            @if ($shopLogoUrl)
                <img src="{{ $shopLogoUrl }}" alt="" class="h-8 w-auto">
            @endif
            <span>{{ $shopName }}</span>
        </a>

        <form role="search" class="order-last w-full md:order-none md:ml-4 md:w-auto md:flex-1 md:max-w-md" onsubmit="return false">
            <label for="header-search" class="sr-only">{{ __('storefront.header.search_label') }}</label>
            <input id="header-search" type="search" placeholder="{{ __('storefront.header.search_placeholder') }}"
                   class="w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-primary focus:outline-none">
        </form>

        <div class="ml-auto flex items-center gap-3">
            @if ($shopPhones !== [])
                <a href="tel:{{ preg_replace('/[^\d+]/', '', $shopPhones[0]) }}" class="hidden text-sm font-medium md:inline" aria-label="{{ __('storefront.header.phone') }}">{{ $shopPhones[0] }}</a>
            @endif
            <a href="#" class="rounded p-2 hover:bg-slate-100" aria-label="{{ __('storefront.header.cart') }}">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l3-8H5.4M7 13L5.4 5M7 13l-2 4h13M9 21a1 1 0 100-2 1 1 0 000 2zm9 0a1 1 0 100-2 1 1 0 000 2z"/></svg>
            </a>
        </div>
    </div>
</header>
