{{-- TM-01: the light entry page of the Mini App (no catalog SQL). The official script is the only external one (TM-13, D-104). --}}
<!DOCTYPE html>
<html lang="ru" @isset($theme) {!! $theme->htmlAttributes() !!} @endisset>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $siteName }}</title>
    @includeIf('storefront.partials.theme-head')
    @vite(['resources/css/app.css'])
    @if ($enabled)
        <script src="https://telegram.org/js/telegram-web-app.js"></script>
    @endif
</head>
<body class="flex min-h-screen items-center justify-center bg-bg px-4 text-ink antialiased" data-tg-entry>
    <main class="w-full max-w-sm text-center" aria-live="polite">
        <section data-state="loading" @unless ($enabled) hidden @endunless>
            <p class="text-lg font-semibold">{{ $siteName }}</p>
            <p class="mt-2 text-ink-mid">{{ __('telegram_miniapp.entry.loading') }}</p>
        </section>

        <section data-state="outside" hidden>
            <h1 class="text-xl font-bold">{{ __('telegram_miniapp.entry.outside_title') }}</h1>
            <p class="mt-2 text-ink-mid">{{ __('telegram_miniapp.entry.outside_text') }}</p>
            <p class="mt-6 flex flex-col gap-3">
                <a href="{{ $siteUrl }}" class="rounded-btn border border-line-strong px-4 py-3 font-medium hover:bg-surface-2">{{ __('telegram_miniapp.entry.open_site') }}</a>
                @if ($botUrl)
                    <a href="{{ $botUrl }}" class="rounded-btn bg-primary px-4 py-3 font-medium text-on-primary hover:opacity-90">{{ __('telegram_miniapp.entry.open_bot') }}</a>
                @endif
            </p>
        </section>

        <section data-state="forbidden" hidden>
            <h1 class="text-xl font-bold" data-msg>{{ __('telegram_miniapp.entry.forbidden') }}</h1>
        </section>

        <section data-state="cookies" hidden>
            <h1 class="text-xl font-bold">{{ __('telegram_miniapp.entry.cookies') }}</h1>
            <p class="mt-6"><a href="{{ $siteUrl }}" class="rounded-btn border border-line-strong px-4 py-3 font-medium hover:bg-surface-2">{{ __('telegram_miniapp.entry.open_site') }}</a></p>
        </section>

        <section data-state="disabled" @if ($enabled) hidden @endif>
            <h1 class="text-xl font-bold">{{ __('telegram_miniapp.entry.disabled') }}</h1>
            <p class="mt-6"><a href="{{ $siteUrl }}" class="rounded-btn border border-line-strong px-4 py-3 font-medium hover:bg-surface-2">{{ __('telegram_miniapp.entry.open_site') }}</a></p>
        </section>
    </main>

    @if ($enabled)
        <script>
            (function () {
                var show = function (name) {
                    document.querySelectorAll('[data-state]').forEach(function (el) { el.hidden = el.dataset.state !== name; });
                };
                var tg = window.Telegram && window.Telegram.WebApp;

                if (!tg || !tg.initData) { show('outside'); return; }

                try { tg.ready(); tg.expand(); } catch (e) {}

                var query = new URLSearchParams(window.location.search);
                var hint = query.get('to') || query.get('start_param');
                var body = { initData: tg.initData };
                if (hint) { body.to = hint; }

                fetch(@js(route('tg.session')), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify(body)
                }).then(function (response) {
                    // 419: the browser did not keep the session cookie (Telegram Web in a browser that blocks it, D-103).
                    if (response.status === 419) { show('cookies'); return null; }
                    return response.json().then(function (data) { return { ok: response.ok, data: data }; });
                }).then(function (result) {
                    if (!result) { return; }
                    if (result.ok && result.data.url) { window.location.replace(result.data.url); return; }
                    show(result.data && result.data.error === 'disabled' ? 'disabled' : 'forbidden');
                }).catch(function () { show('forbidden'); });
            })();
        </script>
    @endif
</body>
</html>
