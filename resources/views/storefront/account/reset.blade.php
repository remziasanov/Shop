@extends('storefront.layouts.app')

@section('content')
    <x-account.card :title="__('account.reset.new_title')" :lead="__('account.reset.new_lead')">
        <form method="POST" action="{{ route('customer.password.update') }}" class="space-y-4" novalidate>
            @csrf
            <x-account.field prefix="rs" name="password" type="password" :label="__('account.fields.new_password')" autocomplete="new-password"
                             :hint="__('account.register.password_hint', ['min' => $passwordMin])" />
            <x-account.field prefix="rs" name="password_confirmation" type="password" :label="__('account.fields.password_confirmation')" autocomplete="new-password" />
            <x-account.button>{{ __('account.reset.save') }}</x-account.button>
        </form>
    </x-account.card>
@endsection
