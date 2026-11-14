{{-- MB-20: manifest, theme color and apple-touch-icon only while the installable app is on. --}}
@php($pwaHead = app(\App\Services\Pwa\PwaService::class)->headData())
@if ($pwaHead)
    <link rel="manifest" href="{{ $pwaHead['manifest'] }}">
    <meta name="theme-color" content="{{ $pwaHead['theme_color'] }}">
    <link rel="apple-touch-icon" href="{{ $pwaHead['apple_icon'] }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ app(\App\Services\Pwa\PwaService::class)->shortName() }}">
@endif
