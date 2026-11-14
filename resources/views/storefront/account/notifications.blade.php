@extends('storefront.layouts.app')

@section('content')
    <div class="mx-auto w-full max-w-2xl py-6">
        <p class="text-sm"><a href="{{ route('account') }}" class="inline-flex min-h-11 items-center text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('account.index.title') }}</a></p>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-bold md:text-3xl">{{ __('account.notifications.title') }}</h1>
            @if ($unreadTotal > 0)
                <form method="POST" action="{{ route('customer.notifications.read-all') }}">
                    @csrf
                    <x-account.button variant="secondary" class="text-sm">{{ __('account.notifications.read_all') }}</x-account.button>
                </form>
            @endif
        </div>
        <p class="mt-1 text-sm text-ink-mid">{{ __('account.notifications.retention', ['days' => \App\Models\CustomerNotification::RETENTION_DAYS]) }}</p>
        <div class="mt-4">
            <x-account.errors />
        </div>

        {{-- TG-07, TG-11: Telegram as a third channel next to e-mail and the cabinet. --}}
        @if ($telegram['available'] || $telegram['linked'])
            <section class="mt-4 rounded-card border border-line p-4" aria-labelledby="account-telegram" data-telegram-block>
                <h2 id="account-telegram" class="font-body font-semibold">{{ __('telegram.cabinet.title') }}</h2>
                @if ($telegram['linked'])
                    <p class="mt-1 text-sm text-ink-mid" data-telegram-linked>{{ __('telegram.cabinet.connected') }}@if ($telegram['username']) ({{ '@'.$telegram['username'] }})@endif</p>
                    @if ($telegram['needsStart'])
                        <p class="mt-1 text-sm text-ink-soft" data-telegram-start-hint>{{ __('telegram.cabinet.start_hint') }}</p>
                    @endif
                    <form method="POST" action="{{ route('customer.telegram.disconnect') }}" class="mt-3">
                        @csrf
                        <x-account.button variant="secondary" class="text-sm">{{ __('telegram.cabinet.disconnect') }}</x-account.button>
                    </form>
                @else
                    <p class="mt-1 text-sm text-ink-mid">{{ __('telegram.cabinet.text') }}</p>
                    <form method="POST" action="{{ route('customer.telegram.connect') }}" class="mt-3">
                        @csrf
                        <x-account.button class="text-sm">{{ __('telegram.cabinet.connect') }}</x-account.button>
                    </form>
                @endif
            </section>
        @endif

        @if ($notifications->isEmpty())
            <p class="rounded-card border border-line p-4 text-ink-mid" data-notifications-empty>{{ __('account.notifications.empty') }}</p>
        @else
            <ul class="space-y-3 md:space-y-0 md:divide-y md:divide-line md:rounded-card md:border md:border-line">
                @foreach ($notifications as $notification)
                    <li class="p-4 max-md:rounded-card max-md:border max-md:border-line {{ $notification->read_at === null ? 'bg-surface-2' : '' }}" data-notification @if ($notification->read_at === null) data-unread @endif>
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <p class="{{ $notification->read_at === null ? 'font-semibold' : 'font-medium' }}">{{ $notification->title }}</p>
                                <p class="text-sm text-ink-soft">{{ $notification->body }}</p>
                                <p class="mt-1 text-xs text-muted">{{ $notification->created_at?->format('d.m.Y H:i') }}</p>
                            </div>
                            <form method="POST" action="{{ route('customer.notifications.read', $notification->id) }}">
                                @csrf
                                <x-account.button variant="secondary" class="text-sm">
                                    {{ $notification->url ? __('account.notifications.open') : __('account.notifications.mark_read') }}
                                </x-account.button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
            {{ $notifications->links('storefront.partials.pagination') }}
        @endif
    </div>
@endsection
