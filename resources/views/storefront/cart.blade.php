@extends('storefront.layouts.app')

@section('title', __('storefront_sales.cart.title'))

@push('head')
    <meta name="robots" content="noindex, follow">
@endpush

@section('content')
    <h1 class="mb-6 text-2xl font-bold md:text-3xl">{{ __('storefront_sales.cart.title') }}</h1>
    <livewire:storefront.cart-page />
@endsection
