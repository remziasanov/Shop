{{-- Home page banner block (5.2). --}}
<section class="mb-8" aria-label="{{ $slide['title'] ?: __('home.storefront.banner') }}">
    @include('storefront.home.blocks._slide', ['slide' => $slide, 'eager' => $first])
</section>
