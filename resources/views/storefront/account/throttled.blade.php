@extends('storefront.layouts.app')

@section('content')
    <div class="mx-auto max-w-md py-10 text-center">
        <h1 class="text-2xl font-bold md:text-3xl">{{ __('account.throttled.title') }}</h1>
        <p class="mt-4 text-slate-700" role="alert">{{ $message }}</p>
        <a href="{{ route('home') }}" class="mt-6 inline-block rounded bg-primary px-4 py-2 font-medium text-white hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('customer_groups.storefront.to_home') }}</a>
    </div>
@endsection
