@extends('storefront.layouts.app')

@section('content')
    <div class="mx-auto w-full max-w-xl py-6">
        <p class="text-sm"><a href="{{ route('account') }}" class="text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('account.index.title') }}</a></p>
        <h1 class="mt-2 text-2xl font-bold md:text-3xl">{{ __('account.security.title') }}</h1>
        <div class="mt-4">
            <x-account.errors />
        </div>

        <section aria-labelledby="security-delete-title">
            <h2 id="security-delete-title" class="text-lg font-semibold">{{ __('account.security.delete_title') }}</h2>
            <p class="mt-2 text-sm text-slate-700">{{ __('account.security.delete_text', ['days' => $graceDays]) }}</p>
            <form method="POST" action="{{ route('customer.security.delete.request') }}" class="mt-4">
                @csrf
                <x-account.button variant="secondary">{{ __('account.security.delete_button') }}</x-account.button>
            </form>
        </section>
    </div>
@endsection
