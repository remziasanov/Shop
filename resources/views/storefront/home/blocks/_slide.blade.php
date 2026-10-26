{{-- One banner / slide of the home page (5.2). $slide from HomePageRenderer, $eager = first screen image (NF-03). --}}
<div class="relative overflow-hidden rounded-lg bg-slate-100">
    <picture>
        @if ($slide['mobileSrcset'])
            <source media="(max-width: 767px)" srcset="{{ $slide['mobileSrcset'] }}" sizes="100vw">
        @endif
        <img src="{{ $slide['src'] }}" srcset="{{ $slide['srcset'] }}" sizes="(min-width: 1280px) 1216px, 100vw"
             width="{{ $slide['width'] }}" height="{{ $slide['height'] }}" alt=""
             @if ($eager) fetchpriority="high" @else loading="lazy" @endif decoding="async"
             class="block h-auto w-full">
    </picture>

    @if ($slide['title'] || $slide['subtitle'] || $slide['buttonText'])
        <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent p-4 text-white md:p-8">
            @if ($slide['title'])
                <h2 class="text-xl font-bold leading-tight md:text-4xl">{{ $slide['title'] }}</h2>
            @endif
            @if ($slide['subtitle'])
                <p class="mt-1 max-w-2xl text-sm md:mt-2 md:text-lg">{{ $slide['subtitle'] }}</p>
            @endif
            @if ($slide['buttonText'])
                <a href="{{ $slide['url'] }}" class="mt-3 inline-block rounded bg-primary px-4 py-2 text-sm font-medium text-white hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white md:mt-4 md:px-6 md:py-3">{{ $slide['buttonText'] }}</a>
            @endif
        </div>
    @endif

    {{-- A link without a button text makes the whole banner clickable. --}}
    @if ($slide['url'] && ! $slide['buttonText'])
        <a href="{{ $slide['url'] }}" class="absolute inset-0 focus-visible:outline focus-visible:outline-4 focus-visible:-outline-offset-4 focus-visible:outline-primary" aria-label="{{ $slide['title'] ?: __('home.storefront.more') }}"></a>
    @endif
</div>
