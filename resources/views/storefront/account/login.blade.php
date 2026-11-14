@extends('storefront.layouts.app')

@section('content')
    @php($inTelegram = \App\Support\TelegramMode::active())
    <x-account.card :title="__('account.login.title')">
        @if ($inTelegram)
            {{-- TM-04 (б): in the Mini App the sign-in is the code from the e-mail; it also links this Telegram to the account. --}}
            <p class="mb-4 rounded-btn border border-line bg-surface-2 p-3 text-sm text-ink-mid" data-tg-login-note>{{ __('telegram_miniapp.login.code_hint') }}</p>
        @endif
        {{-- CA-06: by password, or by a one-time code from the mail. Both forms are on the page, so it works without JavaScript. --}}
        @unless ($inTelegram)
        <section aria-labelledby="login-password-title">
            <h2 id="login-password-title" class="mb-3 text-lg font-semibold">{{ __('account.login.by_password') }}</h2>
            <form method="POST" action="{{ route('customer.login.store') }}" class="space-y-4" novalidate>
                @csrf
                <x-account.field prefix="lg" name="email" type="email" :label="__('account.fields.email')" autocomplete="email" maxlength="190" />
                <x-account.field prefix="lg" name="password" type="password" :label="__('account.fields.password')" autocomplete="current-password" />
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="remember" value="1" @checked(old('remember')) class="h-4 w-4 rounded-btn border-line-heavy focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                    {{ __('account.login.remember') }}
                </label>
                <x-account.button>{{ __('account.login.submit') }}</x-account.button>
            </form>
            <p class="mt-3 text-sm">
                <a href="{{ route('customer.password.forgot') }}" class="text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('account.login.forgot') }}</a>
            </p>
        </section>
        @endunless

        <section class="@unless ($inTelegram) mt-8 border-t border-line pt-6 @endunless" aria-labelledby="login-code-title">
            <h2 id="login-code-title" class="mb-1 text-lg font-semibold">{{ __('account.login.by_code') }}</h2>
            <p class="mb-3 text-sm text-ink-mid">{{ __('account.login.by_code_hint') }}</p>
            <form method="POST" action="{{ route('customer.login.send-code') }}" class="space-y-4" novalidate>
                @csrf
                <x-account.field prefix="lc" name="email" type="email" :label="__('account.fields.email')" autocomplete="email" maxlength="190" />
                <x-account.button :variant="$inTelegram ? 'primary' : 'secondary'" :data-tg-main="$inTelegram ? true : null">{{ __('account.login.send_code') }}</x-account.button>
            </form>
        </section>

        @if ($registrationEnabled)
            <p class="mt-8 text-sm text-ink-mid">
                {{ __('account.login.no_account') }}
                <a href="{{ route('customer.register') }}" class="text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('account.register.submit') }}</a>
            </p>
        @endif
    </x-account.card>
@endsection
