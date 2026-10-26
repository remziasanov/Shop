<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@hasSection('title')@yield('title') — @endif{{ $shopName }}</title>
    @hasSection('meta_description')
        <meta name="description" content="@yield('meta_description')">
    @endif
    @if ($shopFaviconUrl)
        <link rel="icon" href="{{ $shopFaviconUrl }}">
    @endif
    @stack('head')
    <style>
        :root {
            --color-primary: {{ $primaryColor }};
            --color-accent: {{ $accentColor }};
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

    @livewireScripts
</body>
</html>
