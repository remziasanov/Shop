@php
    // TZ 16: cached fragments (the home blocks) never depend on the visitor's cookie (AG-07). The layout itself is not
    // cached, so a visitor whose shop_age cookie already confirms the level gets the page without the overlay and with
    // html[data-age-ok] set here; the inline script does the same in the browser, which keeps a no-JavaScript visitor
    // from being asked again on every page.
    $ageGate = app(\App\Services\Age\AgeGate::class);
    $ageOn = $ageGate->enabled();
    $ageStaff = $ageOn && \App\Http\Middleware\StorefrontOpen::isActiveStaff(request()->user());
    $ageCookie = $ageOn && ! $ageStaff ? $ageGate->confirmedAge(request()) : 0;
@endphp
<!DOCTYPE html>
<html lang="ru" @if ($ageOn) data-age-on @if ($ageGate->showBadge()) data-age-badge @endif @if ($ageStaff) data-age-ok="18" @elseif ($ageCookie > 0) data-age-ok="{{ $ageCookie }}" @endif @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @if ($ageOn)
        {{-- AG-07: the answer lives in the shop_age cookie; this sets html[data-age-ok] before the first paint. --}}
        <script>try{var m=document.cookie.match(/(?:^|; )shop_age=(16|18)/),d=document.documentElement;if(m&&!d.hasAttribute('data-age-ok')){d.setAttribute('data-age-ok',m[1])}}catch(e){}</script>
    @endif
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

    @if ($previewGroup ?? null)
        {{-- CG-18: a staff member looks at the storefront as a customer group. --}}
        <div class="bg-amber-100 px-4 py-2 text-center text-sm text-amber-900" role="status">
            {{ __('customer_groups.storefront.preview_banner', ['group' => $previewGroup]) }}
            <form method="POST" action="{{ route('viewer.preview.exit') }}" class="ml-2 inline">
                @csrf
                <button type="submit" class="font-semibold underline">{{ __('customer_groups.storefront.preview_exit') }}</button>
            </form>
        </div>
    @endif

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
    @if ($ageOn && ! $ageStaff)
        @include('storefront.partials.age-gate', ['gate' => $ageGate])
    @endif

    @livewireScripts
    {!! app(\App\Services\Seo\SeoCounters::class)->bodyCode() !!}
</body>
</html>
