{{-- TM-09: «Открыть в Telegram» on the site (verified startapp links, TG-03; phones only) and «Поделиться» inside the Mini App.
     $param: the startapp parameter (p_{id}, c_{id}); $shareName: the text of the shared message. --}}
@php
    $tgApp = app(\App\Services\Telegram\MiniApp\MiniAppConfig::class);
    $tgInside = \App\Support\TelegramMode::active();
    $tgLink = $tgInside ? $tgApp->startappLink($param) : $tgApp->siteLinkFor($param);
@endphp
@if ($tgLink)
    @if ($tgInside)
        @if (str_starts_with($param, 'p_'))
            <button type="button" data-tg-share="{{ $tgLink }}" data-tg-share-text="{{ $shareName ?? '' }}" class="{{ $class ?? 'mt-3 inline-flex min-h-11 items-center gap-2 rounded-btn border border-line-strong px-4 text-sm font-medium hover:bg-surface-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary' }}" data-tg-share-button>{{ __('telegram_miniapp.share.button') }}</button>
        @endif
    @else
        <a href="{{ $tgLink }}" target="_blank" rel="noopener" class="{{ $class ?? 'mt-3 inline-flex min-h-11 items-center gap-2 rounded-btn border border-line-strong px-4 text-sm font-medium hover:bg-surface-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary' }} md:hidden" data-open-in-telegram>{{ __('telegram_miniapp.open_in_telegram') }}</a>
    @endif
@endif
