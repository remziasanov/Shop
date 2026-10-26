<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@hasSection('title')@yield('title') — @endif{{ $shopName }}</title>
    @if ($shopFaviconUrl)
        <link rel="icon" href="{{ $shopFaviconUrl }}">
    @endif
    <style>
        :root {
            --color-primary: {{ $primaryColor }};
            --color-accent: {{ $accentColor }};
        }
    </style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="flex min-h-screen flex-col bg-white text-slate-900 antialiased">
    <a href="#content" class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:bg-white focus:p-2">{{ __('storefront.skip_to_content') }}</a>

    @include('storefront.partials.header')

    <main id="content" class="mx-auto w-full max-w-7xl flex-1 px-4 py-6">
        @yield('content')
    </main>

    @include('storefront.partials.footer')

    @livewireScripts
</body>
</html>
