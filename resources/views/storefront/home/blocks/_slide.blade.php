{{-- One banner / slide of the home page (5.2). $slide from HomePageRenderer, $eager = first screen image (NF-03).
     TH-12: the presentation follows the theme's hero variant (G1 slide with caption over the image, G2 half and half,
     G4 large type); G3 shows the G1 slide as the big tile of the bento grid. A slide without any text is always an image. --}}
@php
    $hero = \App\Services\Themes\BlockExtras::hero($theme ?? null);
    $hasText = $slide['title'] || $slide['subtitle'] || $slide['buttonText'];
@endphp
@if ($hero === 'G2' && $hasText)
    @include('storefront.home.blocks._slide-g2')
@elseif ($hero === 'G4' && $hasText)
    @include('storefront.home.blocks._slide-g4')
@else
    @include('storefront.home.blocks._slide-g1', ['fill' => $hero === 'G3'])
@endif
