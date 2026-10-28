{{-- Placeholder of the sign-in page until stage 9 (CG-04). The message comes from the redirect of a hidden section. --}}
@extends('storefront.layouts.app')

@section('content')
    <div class="mx-auto max-w-xl py-10 text-center">
        @if (session('access_message'))
            <p class="mb-6 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900" role="status">{{ session('access_message') }}</p>
        @endif
        <h1 class="text-2xl font-bold md:text-3xl">{{ __('customer_groups.storefront.login_title') }}</h1>
        <p class="mt-4 text-slate-600">{{ __('customer_groups.storefront.login_stub') }}</p>
        <a href="{{ route('home') }}" class="mt-6 inline-block rounded bg-primary px-4 py-2 font-medium text-white hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('customer_groups.storefront.to_home') }}</a>
    </div>
@endsection
