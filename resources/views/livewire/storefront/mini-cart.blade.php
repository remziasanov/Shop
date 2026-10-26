<span class="contents">
    <a href="{{ route('cart') }}" class="relative rounded p-2 hover:bg-slate-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
       aria-label="{{ __('storefront_sales.mini_cart.label_count', ['count' => $count]) }}">
        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l3-8H5.4M7 13L5.4 5M7 13l-2 4h13M9 21a1 1 0 100-2 1 1 0 000 2zm9 0a1 1 0 100-2 1 1 0 000 2z"/></svg>
        <span data-cart-count class="absolute -right-1 -top-1 min-w-5 rounded-full bg-accent px-1 text-center text-xs font-semibold leading-5 text-slate-900 {{ $count > 0 ? '' : 'hidden' }}" aria-hidden="true">{{ $count }}</span>
    </a>
</span>
