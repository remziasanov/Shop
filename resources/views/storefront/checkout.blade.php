@extends('storefront.layouts.app')

@section('title', __('storefront_sales.checkout.title'))

@push('head')
    <meta name="robots" content="noindex, nofollow">
@endpush

@section('content')
    <h1 class="mb-6 text-2xl font-bold md:text-3xl">{{ __('storefront_sales.checkout.title') }}</h1>
    <livewire:storefront.checkout-form />
@endsection
