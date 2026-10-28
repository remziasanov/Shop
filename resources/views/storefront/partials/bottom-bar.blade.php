{{-- SF-03: bottom bar on mobile; the cart item appears only when the cart exists in the shop (SF-01). --}}
<nav class="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white md:hidden" aria-label="{{ __('storefront.bottom_bar.label') }}">
    <ul class="grid text-xs {{ ['2' => 'grid-cols-2', '3' => 'grid-cols-3', '4' => 'grid-cols-4'][(string) (2 + ($cartEnabled ? 1 : 0) + ($accountLinks ? 1 : 0))] }}">
        <li>
            <a href="{{ route('catalog.index') }}" class="flex flex-col items-center gap-1 py-2 hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h7v7H4zM13 6h7v7h-7zM4 15h7v5H4zM13 15h7v5h-7z"/></svg>
                {{ __('storefront.bottom_bar.catalog') }}
            </a>
        </li>
        <li>
            <a href="{{ route('search') }}" class="flex flex-col items-center gap-1 py-2 hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
               x-data @click.prevent="window.scrollTo({ top: 0 }); document.getElementById('header-search').focus()">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.3-4.3M10.5 18a7.5 7.5 0 100-15 7.5 7.5 0 000 15z"/></svg>
                {{ __('storefront.bottom_bar.search') }}
            </a>
        </li>
        @if ($accountLinks)
            <li>
                <a href="{{ $accountCustomerName !== null ? route('account') : route('customer.login') }}" class="flex flex-col items-center gap-1 py-2 hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" data-account-bar>
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5.5 20a6.5 6.5 0 0113 0M12 12a4 4 0 100-8 4 4 0 000 8z"/></svg>
                    {{ $accountCustomerName !== null ? __('account.header.account') : __('account.header.login') }}
                </a>
            </li>
        @endif
        @if ($cartEnabled)
            <livewire:storefront.mini-cart variant="bar" />
        @endif
    </ul>
</nav>
