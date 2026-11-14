{{-- The layout every storefront and cabinet page extends. TM-06: in the Telegram mode (a session flag, never a URL parameter) the same pages render in layouts.telegram; otherwise in the site layout. --}}
@extends(\App\Support\TelegramMode::active() ? 'storefront.layouts.telegram' : 'storefront.layouts.site')
