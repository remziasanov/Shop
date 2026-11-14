@php
    // TM-06, TM-11: the layout of the Telegram Mini App. Same pages, same blocks, same theme tokens (TH-15): no site header
    // or footer, a compact search + cart row on top, the bottom bar (MB-03) below. Age gate and closed shop work as on the site.
    $ageGate = app(\App\Services\Age\AgeGate::class);
    $ageOn = $ageGate->enabled();
    $ageStaff = $ageOn && \App\Http\Middleware\StorefrontOpen::isActiveStaff(request()->user());
    $ageCookie = $ageOn && ! $ageStaff ? $ageGate->confirmedAge(request()) : 0;
    $tgConfig = [
        'colors' => app(\App\Services\Telegram\MiniApp\MiniAppConfig::class)->colorsMode(),
        'leaveUrl' => route('tg.leave', [], false),
        'writeUrl' => route('tg.write-access', [], false),
        'phoneUrl' => route('tg.phone', [], false),
        'csrf' => csrf_token(),
    ];
@endphp
<!DOCTYPE html>
<html lang="ru" @isset($theme) {!! $theme->htmlAttributes() !!} @endisset data-tg data-tg-colors="{{ $tgConfig['colors'] }}" @if ($ageOn) data-age-on @if ($ageStaff) data-age-ok="18" @elseif ($ageCookie > 0) data-age-ok="{{ $ageCookie }}" @endif @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- The Mini App is never an entry from search: the same addresses are indexed in their site form. --}}
    <meta name="robots" content="noindex,nofollow">
    <title>{{ trim($__env->yieldContent('title')) !== '' ? trim($__env->yieldContent('title')) : $shopName }}</title>
    @if ($ageOn)
        <script>try{var m=document.cookie.match(/(?:^|; )shop_age=(16|18)/),d=document.documentElement;if(m&&!d.hasAttribute('data-age-ok')){d.setAttribute('data-age-ok',m[1])}}catch(e){}</script>
    @endif
    {{-- TM-13, D-104: the official script, the only external one; it must come first so that the bridge finds it. --}}
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <link rel="icon" href="{{ $shopFaviconUrl ?: asset('favicon.ico') }}">
    {!! $__env->yieldPushContent('head') !!}
    @includeIf('storefront.partials.theme-head')
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/telegram.js'])
    @livewireStyles
</head>
@php($noBottomNav = $__env->hasSection('no_bottom_nav'))
<body class="flex min-h-screen flex-col overflow-x-clip bg-bg pb-[var(--shop-nav-total)] text-ink antialiased" x-data="tgBridge(@js($tgConfig))" @if ($noBottomNav) data-nav="off" @endif @if ($__env->hasSection('sticky_bar')) data-sticky @endif>
    @include('storefront.partials.tg-topbar')

    <main id="content" class="mx-auto w-full max-w-7xl flex-1 px-4 py-4">
        @if (session('promo_link_notice'))
            <p role="status" class="mb-4 rounded-card border border-warn-line bg-warn-wash p-3 text-warn-ink" data-promo-link-notice>{{ session('promo_link_notice') }}</p>
        @endif
        @if (session(\App\Services\Telegram\MiniApp\TelegramStartParamResolver::NOTICE_KEY))
            <p role="status" class="mb-4 rounded-card border border-warn-line bg-warn-wash p-3 text-warn-ink" data-tg-notice>{{ session(\App\Services\Telegram\MiniApp\TelegramStartParamResolver::NOTICE_KEY) }}</p>
        @endif
        @yield('content')
    </main>

    @unless ($noBottomNav)
        @include('storefront.partials.bottom-bar')
    @endunless
    @if ($cartEnabled)
        @include('storefront.partials.cart-toast')
    @endif

    @include('storefront.partials.cookie-banner')
    @if ($ageOn && ! $ageStaff)
        @include('storefront.partials.age-gate', ['gate' => $ageGate])
    @endif

    @livewireScripts
</body>
</html>
