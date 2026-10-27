{{-- Title, description, canonical, robots, Open Graph, JSON-LD, verification tags and head counters (SF-81…SF-84). --}}
@php
    $counters = app(\App\Services\Seo\SeoCounters::class);
    $ogImage = $seo->ogImage;
@endphp
    <title>{{ $seo->title }}</title>
    @if ($seo->description !== '')
        <meta name="description" content="{{ $seo->description }}">
    @endif
    @if ($seo->canonical)
        <link rel="canonical" href="{{ $seo->canonical }}">
    @endif
    @if ($seo->robots)
        <meta name="robots" content="{{ $seo->robots }}">
    @endif
    <meta property="og:site_name" content="{{ $shopName }}">
    <meta property="og:locale" content="ru_RU">
    <meta property="og:type" content="{{ $seo->ogType }}">
    <meta property="og:title" content="{{ $seo->ogTitle ?? $seo->title }}">
    @if (($seo->ogDescription ?? $seo->description) !== '')
        <meta property="og:description" content="{{ $seo->ogDescription ?? $seo->description }}">
    @endif
    <meta property="og:url" content="{{ $seo->ogUrl ?? $seo->canonical ?? url()->current() }}">
    @if ($ogImage)
        <meta property="og:image" content="{{ $ogImage }}">
    @endif
    <meta name="twitter:card" content="{{ $ogImage ? 'summary_large_image' : 'summary' }}">
    <meta name="twitter:title" content="{{ $seo->ogTitle ?? $seo->title }}">
    @foreach ($counters->verificationTags() as $tag)
        {!! $tag !!}
    @endforeach
    @foreach ($seo->jsonLd as $data)
        <script type="application/ld+json">{!! \App\Services\Seo\JsonLd::encode($data) !!}</script>
    @endforeach
    @if ($counters->headCode() !== '')
        {!! $counters->headCode() !!}
    @endif
