@extends('storefront.layouts.app')

@section('content')
    <x-account.card :title="__('account.profile.email_confirm_title')" :lead="__('account.profile.email_confirm_lead', ['email' => $maskedEmail])">
        <form method="POST" action="{{ route('customer.email.confirm.store') }}" class="space-y-4" novalidate>
            @csrf
            <x-account.field prefix="ec" name="code" :label="__('account.fields.code')" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" />
            <x-account.button>{{ __('account.profile.email_confirm_submit') }}</x-account.button>
        </form>
        <p class="mt-6 text-sm">
            <a href="{{ route('customer.profile') }}" class="text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('account.profile.back') }}</a>
        </p>
    </x-account.card>
@endsection
