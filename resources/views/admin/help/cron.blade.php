<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('dashboard.cron.help_title') }}</title>
    <style>
        :root { --bg: #f9fafb; --fg: #111827; --muted: #6b7280; --card: #fff; --border: #e5e7eb; --code: #f3f4f6; --link: #2563eb; }
        @media (prefers-color-scheme: dark) { :root { --bg: #111827; --fg: #f9fafb; --muted: #9ca3af; --card: #1f2937; --border: #374151; --code: #0b1220; --link: #60a5fa; } }
        body { margin: 0; padding: 24px 16px; background: var(--bg); color: var(--fg); font: 16px/1.5 system-ui, sans-serif; }
        main { max-width: 52rem; margin: 0 auto; background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 24px; }
        h1 { margin: 0 0 12px; font-size: 1.5rem; }
        h2 { margin: 24px 0 8px; font-size: 1.05rem; }
        pre { margin: 0; padding: 12px; overflow-x: auto; background: var(--code); border-radius: 8px; font-size: .85rem; white-space: pre-wrap; word-break: break-all; }
        p.note { color: var(--muted); }
        a { color: var(--link); }
    </style>
</head>
<body>
<main>
    <h1>{{ __('dashboard.cron.help_title') }}</h1>
    <p>{{ __('dashboard.cron.help_intro') }}</p>

    <h2>{{ __('dashboard.cron.scheduler') }}</h2>
    <pre data-testid="cron-scheduler">{{ $scheduler }}</pre>

    <h2>{{ __('dashboard.cron.queue') }}</h2>
    <pre data-testid="cron-queue">{{ $queue }}</pre>

    <p class="note">{{ __('dashboard.cron.note') }}</p>
    <p><a href="{{ $panelUrl }}">{{ __('dashboard.cron.back') }}</a></p>
</main>
</body>
</html>
