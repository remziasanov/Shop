{{-- G1 (and G3's big tile): the image with the caption over it. $fill: stretch to the height of the bento tile. --}}
@php
    $fill = $fill ?? false;
    $heroPattern = str_contains(\App\Services\Themes\BlockExtras::heroStyle($theme ?? null), '--shop-hero-image');
@endphp
<div class="relative overflow-hidden rounded-card bg-surface-3 {{ $fill ? 'md:h-full' : '' }}" data-hero-slide>
    @include('storefront.home.blocks._picture', ['class' => 'block h-auto w-full'.($fill ? ' md:h-full md:object-cover' : '')])

    @if ($heroPattern)
        {{-- heroImage extra: a halftone / pattern laid over the image. --}}
        <div class="hero-pattern pointer-events-none absolute inset-0" aria-hidden="true"></div>
    @endif

    @if ($slide['title'] || $slide['subtitle'] || $slide['buttonText'])
        <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-scrim/70 to-transparent p-4 text-on-solid md:p-8">
            @if ($slide['title'])
                <h2 class="text-xl font-bold leading-tight {{ $fill ? 'md:text-3xl' : 'md:text-4xl' }}">{{ $slide['title'] }}</h2>
            @endif
            @if ($slide['subtitle'])
                <p class="mt-1 max-w-2xl text-sm md:mt-2 md:text-lg">{{ $slide['subtitle'] }}</p>
            @endif
            @if ($slide['buttonText'])
                <a href="{{ $slide['url'] }}" class="hero-btn mt-3 inline-block rounded-btn bg-primary px-4 py-2 text-sm font-medium text-on-primary hover:opacity-90 active:opacity-80 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-bg motion-reduce:transition-none md:mt-4 md:px-6 md:py-3">{{ $slide['buttonText'] }}</a>
            @endif
        </div>
    @endif

    {{-- A link without a button text makes the whole banner clickable. --}}
    @if ($slide['url'] && ! $slide['buttonText'])
        <a href="{{ $slide['url'] }}" class="absolute inset-0 focus-visible:outline focus-visible:outline-4 focus-visible:-outline-offset-4 focus-visible:outline-primary" aria-label="{{ $slide['title'] ?: __('home.storefront.more') }}"></a>
    @endif
</div>
