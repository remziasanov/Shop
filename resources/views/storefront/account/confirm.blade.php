@extends('storefront.layouts.app')

@section('content')
    <x-account.card :title="__('account.confirm.title')" :lead="__('account.confirm.lead', ['email' => $maskedEmail])">
        <form method="POST" action="{{ route('customer.confirm.store') }}" class="space-y-4" novalidate>
            @csrf
            <x-account.field prefix="cf" name="code" :label="__('account.fields.code')" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" />
            <x-account.button>{{ __('account.confirm.submit') }}</x-account.button>
        </form>

        <x-account.resend-button :action="route('customer.confirm.resend')" :seconds="$resendSeconds" />
    </x-account.card>
@endsection
