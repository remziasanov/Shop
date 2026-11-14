{{-- Home page banner block (5.2). TH-12: G2 / G4 put it on the hero band, G3 shows it as the big tile of the bento grid. --}}
@php
    $hero = \App\Services\Themes\BlockExtras::hero($theme ?? null);
    $bento = $bento ?? false;
    $band = in_array($hero, ['G2', 'G4'], true);
@endphp
<section @class([$bento ? 'h-full' : 'mb-8', 'hero-band' => $band, 'hero-band-first' => $band && $first]) aria-label="{{ $slide['title'] ?: __('home.storefront.banner') }}"
         data-hero="{{ $hero }}" @if (($heroStyle = \App\Services\Themes\BlockExtras::heroStyle($theme ?? null)) !== '') style="{{ $heroStyle }}" @endif>
    @if ($band)
        <div class="hero-band-inner">
            @include('storefront.home.blocks._slide', ['slide' => $slide, 'eager' => $first])
        </div>
    @else
        @include('storefront.home.blocks._slide', ['slide' => $slide, 'eager' => $first])
    @endif
</section>
