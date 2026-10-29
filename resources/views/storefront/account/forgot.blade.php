@extends('storefront.layouts.app')

@section('content')
    <x-account.card :title="__('account.reset.title')" :lead="__('account.reset.lead')">
        <form method="POST" action="{{ route('customer.password.send-code') }}" class="space-y-4" novalidate>
            @csrf
            <x-account.field prefix="fg" name="email" type="email" :label="__('account.fields.email')" autocomplete="email" maxlength="190" />
            <x-account.button>{{ __('account.reset.send_code') }}</x-account.button>
        </form>
        <p class="mt-6 text-sm">
            <a href="{{ route('customer.login') }}" class="text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('account.login.back') }}</a>
        </p>
    </x-account.card>
@endsection
