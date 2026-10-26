{{-- SF-03: bottom bar on mobile. The cart link is a placeholder until stage 4. --}}
<nav class="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white md:hidden" aria-label="{{ __('storefront.bottom_bar.label') }}">
    <ul class="grid grid-cols-3 text-xs">
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
        <li>
            <a href="#" class="flex flex-col items-center gap-1 py-2 hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l3-8H5.4M7 13L5.4 5M7 13l-2 4h13M9 21a1 1 0 100-2 1 1 0 000 2zm9 0a1 1 0 100-2 1 1 0 000 2z"/></svg>
                {{ __('storefront.bottom_bar.cart') }}
            </a>
        </li>
    </ul>
</nav>
