@extends('storefront.layouts.app')

@section('content')
    <div class="mx-auto w-full max-w-xl py-6">
        <p class="text-sm"><a href="{{ route('account') }}" class="inline-flex min-h-11 items-center text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('account.index.title') }}</a></p>
        <h1 class="mt-2 text-2xl font-bold md:text-3xl">{{ __('phone_verification.screen.page_title') }}</h1>
        <div class="mt-4">
            <x-account.errors />
        </div>
        <livewire:storefront.phone-verification-panel />
    </div>
@endsection
