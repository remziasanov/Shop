@extends('storefront.layouts.app')

@section('content')
    <div class="mx-auto w-full max-w-xl py-6">
        <p class="text-sm"><a href="{{ route('account') }}" class="text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('account.index.title') }}</a></p>
        <h1 class="mt-2 text-2xl font-bold md:text-3xl">{{ __('account.company.title') }}</h1>
        <p class="mt-1 text-sm text-slate-600">{{ __('account.company.lead') }}</p>
        <div class="mt-4">
            <x-account.errors />
        </div>

        <form method="POST" action="{{ route('customer.company.save') }}" class="space-y-4" novalidate>
            @csrf
            <x-account.field prefix="cm" name="name" :label="__('account.company.fields.name')" :value="$company?->name" autocomplete="organization" maxlength="255" />
            <x-account.field prefix="cm" name="inn" :label="__('account.company.fields.inn')" :value="$company?->inn" inputmode="numeric" maxlength="12" :hint="__('account.company.inn_hint')" />
            <x-account.field prefix="cm" name="kpp" :label="__('account.company.fields.kpp')" :value="$company?->kpp" :required="false" maxlength="9" :hint="__('account.company.kpp_hint')" />
            <x-account.field prefix="cm" name="ogrn" :label="__('account.company.fields.ogrn')" :value="$company?->ogrn" :required="false" inputmode="numeric" maxlength="15" :hint="__('account.company.ogrn_hint')" />
            <x-account.field prefix="cm" name="legal_address" :label="__('account.company.fields.legal_address')" :value="$company?->legal_address" autocomplete="street-address" maxlength="1000" />
            <x-account.button>{{ __('account.company.save') }}</x-account.button>
        </form>

        @if ($company)
            <form method="POST" action="{{ route('customer.company.destroy') }}" class="mt-6 border-t border-slate-200 pt-6">
                @csrf
                <x-account.button variant="secondary">{{ __('account.company.delete') }}</x-account.button>
            </form>
        @endif
    </div>
@endsection
