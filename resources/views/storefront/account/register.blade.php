@extends('storefront.layouts.app')

@section('content')
    <x-account.card :title="__('account.register.title')" :lead="__('account.register.lead')">
        <form method="POST" action="{{ route('customer.register.store') }}" class="relative space-y-4" novalidate>
            @csrf
            <x-account.field prefix="rg" name="name" :label="__('account.fields.name')" autocomplete="name" maxlength="150" />
            <x-account.field prefix="rg" name="email" type="email" :label="__('account.fields.email')" autocomplete="email" maxlength="190" :value="$prefillEmail" />
            <x-account.field prefix="rg" name="phone" type="tel" :label="__('account.fields.phone')" autocomplete="tel" inputmode="tel"
                             :placeholder="__('storefront_sales.checkout.phone_placeholder')" x-data="phoneMask" x-on:input="format($event)" />
            <x-account.field prefix="rg" name="password" type="password" :label="__('account.fields.password')" autocomplete="new-password"
                             :hint="__('account.register.password_hint', ['min' => $passwordMin])" />
            <x-account.field prefix="rg" name="password_confirmation" type="password" :label="__('account.fields.password_confirmation')" autocomplete="new-password" />

            {{-- CA-02: consent, never checked by default; the time and the IP are stored with the account. --}}
            <div class="flex items-start gap-2">
                <input id="rg-consent" name="consent" type="checkbox" value="1" required aria-required="true" @checked(old('consent'))
                       class="mt-1 h-4 w-4 flex-none rounded border-slate-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
                       @if ($errors->has('consent')) aria-invalid="true" @endif>
                <label for="rg-consent" class="text-sm">
                    {{ $consentLink['text'] }} <span class="text-red-700" aria-hidden="true">*</span>
                    @if (! empty($consentLink['url']))
                        <a href="{{ $consentLink['url'] }}" target="_blank" rel="noopener" class="whitespace-nowrap text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('storefront_sales.checkout.consent_link') }}</a>
                    @endif
                </label>
            </div>

            {{-- Spam protection (CA-02): the honeypot is hidden from people; the token proves how long the form was open. --}}
            <div class="absolute -left-[9999px] h-0 w-0 overflow-hidden" aria-hidden="true">
                <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
            </div>
            <input type="hidden" name="form_token" value="{{ $formToken }}">

            <x-account.button>{{ __('account.register.submit') }}</x-account.button>
        </form>

        <p class="mt-6 text-sm text-slate-600">
            {{ __('account.register.have_account') }}
            <a href="{{ route('customer.login') }}" class="text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('account.login.submit') }}</a>
        </p>
    </x-account.card>
@endsection
