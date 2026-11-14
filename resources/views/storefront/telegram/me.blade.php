@extends('storefront.layouts.app')

@section('title', __('telegram_miniapp.me.title'))

@section('content')
    {{-- TM-04 (б), (в): the cabinet of a visitor without an account. --}}
    <div class="mx-auto w-full max-w-3xl py-2">
        <h1 class="text-2xl font-bold">{{ __('telegram_miniapp.me.title') }}</h1>
        @if ($telegram !== null)
            <p class="mt-2 text-ink-mid" data-tg-me-name>{{ __('telegram_miniapp.me.greeting', ['name' => trim($telegram->first_name.' '.$telegram->last_name) !== '' ? trim($telegram->first_name.' '.$telegram->last_name) : ($telegram->username ?? '')]) }}</p>
        @endif
        <p class="mt-2 text-sm text-ink-mid">{{ __('telegram_miniapp.me.hint') }}</p>

        <ul class="mt-6 grid gap-3">
            <li>
                <a href="{{ route('customer.login') }}" class="block rounded-card border border-line p-4 hover:border-primary" data-tg-login>
                    <span class="block font-semibold">{{ __('telegram_miniapp.me.login') }}</span>
                    <span class="block text-sm text-ink-mid">{{ __('telegram_miniapp.me.login_hint') }}</span>
                </a>
            </li>
            @if ($registrationEnabled)
                <li>
                    <a href="{{ route('customer.register') }}" class="block rounded-card border border-line p-4 hover:border-primary" data-tg-register>
                        <span class="block font-semibold">{{ __('telegram_miniapp.me.register') }}</span>
                        <span class="block text-sm text-ink-mid">{{ __('telegram_miniapp.me.register_hint') }}</span>
                    </a>
                </li>
            @endif
            <li>
                <a href="{{ route('tg.about') }}" class="block rounded-card border border-line p-4 hover:border-primary" data-tg-about>
                    <span class="block font-semibold">{{ __('telegram_miniapp.about.title') }}</span>
                    <span class="block text-sm text-ink-mid">{{ __('telegram_miniapp.about.hint') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('home') }}" target="_blank" rel="noopener" data-tg-browser class="block rounded-card border border-line p-4 hover:border-primary" data-tg-open-site>
                    <span class="block font-semibold">{{ __('telegram_miniapp.me.open_site') }}</span>
                </a>
            </li>
        </ul>
    </div>
@endsection
