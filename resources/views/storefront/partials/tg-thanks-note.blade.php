{{-- TM-05: in the Mini App, when the bot cannot write to the buyer (no chat started, write access refused) the page says so. --}}
@if (\App\Support\TelegramMode::active() && ! session(\App\Support\TelegramMode::CHAT_KEY))
    <p class="mt-3 rounded-btn border border-warn-line bg-warn-wash p-3 text-sm text-warn-ink" role="status" data-tg-no-notifications>{{ __('telegram_miniapp.checkout.no_notifications') }}</p>
@endif
