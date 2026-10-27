<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        $headStack = $__env->yieldPushContent('head');
        $seo ??= app(\App\Services\Seo\SeoBuilder::class)->fallback(
            trim($__env->yieldContent('title')),
            trim($__env->yieldContent('meta_description')),
            isset($breadcrumbs) && is_array($breadcrumbs) ? $breadcrumbs : null,
        );
        // Views that still push their own canonical / robots tags keep them (no duplicates).
        if (str_contains($headStack, 'rel="canonical"')) {
            $seo = $seo->withoutCanonical();
        }
        if (str_contains($headStack, 'name="robots"')) {
            $seo = $seo->withoutRobots();
        }
    @endphp
    @include('storefront.partials.seo-head', ['seo' => $seo])
    <link rel="icon" href="{{ $shopFaviconUrl ?: asset('favicon.ico') }}">
    {!! $headStack !!}
    <style>
        :root {
            --shop-primary: {{ $primaryColor }};
            --shop-accent: {{ $accentColor }};
        }
    </style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="flex min-h-screen flex-col bg-white pb-16 text-slate-900 antialiased md:pb-0">
    <a href="#content" class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:bg-white focus:p-2">{{ __('storefront.skip_to_content') }}</a>

    @include('storefront.partials.header')

    <main id="content" class="mx-auto w-full max-w-7xl flex-1 px-4 py-6">
        @yield('content')
    </main>

    @include('storefront.partials.footer')
    @include('storefront.partials.bottom-bar')
    @if ($cartEnabled)
        @include('storefront.partials.cart-toast')
    @endif

    @include('storefront.partials.cookie-banner')

    @livewireScripts
    {!! app(\App\Services\Seo\SeoCounters::class)->bodyCode() !!}
</body>
</html>
