@extends('storefront.layouts.app')

@section('content')
    <x-account.card :title="__('account.reset.code_title')" :lead="__('account.reset.code_lead')">
        <form method="POST" action="{{ route('customer.password.verify') }}" class="space-y-4" novalidate>
            @csrf
            <x-account.field prefix="fc" name="code" :label="__('account.fields.code')" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" />
            <x-account.button>{{ __('account.reset.verify') }}</x-account.button>
        </form>
        <x-account.resend-button :action="route('customer.password.resend')" :seconds="$resendSeconds" />
    </x-account.card>
@endsection
