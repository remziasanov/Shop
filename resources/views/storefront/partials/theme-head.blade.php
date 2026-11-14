{{-- TH-05, TH-18, TH-19: tokens and the @font-face rules of the active theme only; fonts are served from public/fonts. --}}
@foreach ($themeAssets['preload'] as $fontUrl)
    <link rel="preload" href="{{ $fontUrl }}" as="font" type="font/woff2" crossorigin>
@endforeach
<style>{!! $themeAssets['css'] !!}</style>
{{-- TH-17: content stays visible without JavaScript: folded blocks (collapse, product accordion) are open. --}}
<noscript>
<style>[data-collapse]>[role="group"]{display:block!important}[data-accordion]+[role="tabpanel"]{display:block!important}
</style>
</noscript>
