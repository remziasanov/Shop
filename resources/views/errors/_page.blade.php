{{-- Expects $code (int|null), $title, $text, $links (bool). Minimal storefront-styled shell for error pages (SF-07). Must render even when the database is down: settings are read defensively. --}}
@php
    $shop = \App\Support\ShopName::get();
    $primary = rescue(fn () => app(\App\Settings\AppearanceSettings::class)->primary_color, '#2563eb', false);
    $accent = rescue(fn () => app(\App\Settings\AppearanceSettings::class)->accent_color, '#f59e0b', false);
    $hex = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';
    $primary = is_string($primary) && preg_match($hex, $primary) === 1 ? $primary : '#2563eb';
    $accent = is_string($accent) && preg_match($hex, $accent) === 1 ? $accent : '#f59e0b';
    $css = rescue(fn () => (string) app(\Illuminate\Foundation\Vite::class)(['resources/css/app.css']), '', false);
    $homeUrl = rescue(fn () => route('home'), '/', false);
    $catalogUrl = rescue(fn () => route('catalog.index'), '/catalog', false);
    $searchUrl = rescue(fn () => route('search'), '/search', false);
@endphp
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} — {{ $shop }}</title>
    <style>
        :root { --shop-primary: {{ $primary }}; --shop-accent: {{ $accent }}; }
    </style>
    {!! $css !!}
</head>
<body class="flex min-h-screen flex-col bg-white text-slate-900 antialiased">
    <header class="border-b border-slate-200">
        <div class="mx-auto flex max-w-7xl items-center px-4 py-4">
            <a href="{{ $homeUrl }}" class="text-xl font-bold text-primary">{{ $shop }}</a>
        </div>
    </header>

    <main class="mx-auto w-full max-w-3xl flex-1 px-4 py-16 text-center">
        @if ($code !== null)
            <p class="text-6xl font-bold text-primary" aria-hidden="true">{{ $code }}</p>
        @endif
        <h1 class="mt-4 text-2xl font-bold md:text-3xl">{{ $title }}</h1>
        <p class="mt-3 whitespace-pre-line text-slate-600">{{ $text }}</p>

        @if ($links)
            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <a href="{{ $homeUrl }}" class="rounded bg-primary px-5 py-2 font-medium text-white hover:opacity-90">{{ __('seo.errors.home') }}</a>
                <a href="{{ $catalogUrl }}" class="rounded border border-slate-300 px-5 py-2 font-medium hover:bg-slate-50">{{ __('seo.errors.catalog') }}</a>
            </div>

            <form action="{{ $searchUrl }}" method="get" class="mx-auto mt-6 flex max-w-md gap-2" role="search">
                <label for="error-search" class="sr-only">{{ __('seo.errors.search_placeholder') }}</label>
                <input id="error-search" name="q" type="search" placeholder="{{ __('seo.errors.search_placeholder') }}" class="w-full rounded border border-slate-300 px-3 py-2">
                <button type="submit" class="rounded border border-slate-300 px-4 py-2 hover:bg-slate-50">{{ __('seo.errors.search') }}</button>
            </form>
        @endif
    </main>

    <footer class="border-t border-slate-200 py-6 text-center text-sm text-slate-500">&copy; {{ date('Y') }} {{ $shop }}</footer>
</body>
</html>
