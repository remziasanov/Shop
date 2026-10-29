@extends('storefront.layouts.app')

@section('content')
    <div class="mx-auto w-full max-w-xl py-6">
        <p class="text-sm"><a href="{{ route('account') }}" class="text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('account.index.title') }}</a></p>
        <h1 class="mt-2 text-2xl font-bold md:text-3xl">{{ __('account.profile.title') }}</h1>
        <div class="mt-4">
            <x-account.errors />
        </div>

        <form method="POST" action="{{ route('customer.profile.update') }}" class="space-y-4" novalidate>
            @csrf
            <h2 class="text-lg font-semibold">{{ __('account.profile.data') }}</h2>
            <x-account.field prefix="pf" name="name" :label="__('account.fields.name')" :value="$customer->name" autocomplete="name" maxlength="150" />
            <x-account.field prefix="pf" name="phone" type="tel" :label="__('account.fields.phone')" :value="$customer->phone" autocomplete="tel" inputmode="tel"
                             x-data="phoneMask" x-on:input="format($event)" />
            <x-account.button>{{ __('account.profile.save') }}</x-account.button>
        </form>

        <section class="mt-8 border-t border-slate-200 pt-6" aria-labelledby="profile-email-title">
            <h2 id="profile-email-title" class="text-lg font-semibold">{{ __('account.profile.email_title') }}</h2>
            <p class="mb-3 text-sm text-slate-600">{{ __('account.profile.email_current', ['email' => $customer->email]) }}</p>
            <form method="POST" action="{{ route('customer.email.request') }}" class="space-y-4" novalidate>
                @csrf
                <x-account.field prefix="pe" name="email" type="email" :label="__('account.profile.new_email')" autocomplete="email" maxlength="190" />
                <x-account.button variant="secondary">{{ __('account.profile.email_send_code') }}</x-account.button>
            </form>
        </section>

        <section class="mt-8 border-t border-slate-200 pt-6" aria-labelledby="profile-password-title">
            <h2 id="profile-password-title" class="text-lg font-semibold">{{ __('account.profile.password_title') }}</h2>
            <form method="POST" action="{{ route('customer.profile.password') }}" class="mt-3 space-y-4" novalidate>
                @csrf
                <x-account.field prefix="pp" name="current_password" type="password" :label="__('account.fields.current_password')" autocomplete="current-password" />
                <x-account.field prefix="pp" name="password" type="password" :label="__('account.fields.new_password')" autocomplete="new-password"
                                 :hint="__('account.register.password_hint', ['min' => $passwordMin])" />
                <x-account.field prefix="pp" name="password_confirmation" type="password" :label="__('account.fields.password_confirmation')" autocomplete="new-password" />
                <x-account.button variant="secondary">{{ __('account.profile.password_save') }}</x-account.button>
            </form>
        </section>
    </div>
@endsection
