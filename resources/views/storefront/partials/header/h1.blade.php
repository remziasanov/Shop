{{-- TH-10, H1: two rows. Row 1: logo, search, icons; row 2: categories. Identical to the header before themes. --}}
<div class="mx-auto flex w-full max-w-7xl flex-wrap items-center gap-3 px-4 py-3">
    @include('storefront.partials.header.burger')
    @include('storefront.partials.header.logo')
    @if ($searchEnabled)
        @include('storefront.partials.header.search', ['class' => 'relative order-last w-full md:order-none md:ml-4 md:w-auto md:max-w-md md:flex-1'])
    @endif
    <div class="ml-auto flex items-center gap-3">
        @include('storefront.partials.header.icons')
    </div>
</div>

@if ($menuCategories !== [])
    @include('storefront.partials.header.nav', ['class' => 'hidden border-t border-hairline md:block', 'listClass' => 'mx-auto flex w-full max-w-7xl flex-wrap gap-x-1 px-4 text-sm'])
@endif
