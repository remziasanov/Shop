@extends('storefront.layouts.app')

@section('content')
    <x-account.card :title="__('account.login.code_title')" :lead="__('account.login.code_lead')">
        <form method="POST" action="{{ route('customer.login.verify') }}" class="space-y-4" novalidate>
            @csrf
            <x-account.field prefix="lv" name="code" :label="__('account.fields.code')" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" />
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="remember" value="1" @checked(old('remember')) class="h-4 w-4 rounded border-slate-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                {{ __('account.login.remember') }}
            </label>
            <x-account.button>{{ __('account.login.submit') }}</x-account.button>
        </form>
        <x-account.resend-button :action="route('customer.login.resend')" :seconds="$resendSeconds" />
        <p class="mt-6 text-sm">
            <a href="{{ route('customer.login') }}" class="text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('account.login.back') }}</a>
        </p>
    </x-account.card>
@endsection
