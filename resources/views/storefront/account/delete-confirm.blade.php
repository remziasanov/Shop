@extends('storefront.layouts.app')

@section('content')
    <x-account.card :title="__('account.security.confirm_title')" :lead="__('account.security.confirm_lead', ['email' => $maskedEmail, 'days' => $graceDays])">
        <form method="POST" action="{{ route('customer.security.delete.confirm') }}" class="space-y-4" novalidate>
            @csrf
            <x-account.field prefix="dc" name="code" :label="__('account.fields.code')" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" />
            <x-account.button>{{ __('account.security.confirm_submit') }}</x-account.button>
        </form>
        <p class="mt-6 text-sm">
            <a href="{{ route('customer.security') }}" class="text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('account.security.cancel') }}</a>
        </p>
    </x-account.card>
@endsection
