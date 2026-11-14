@extends('storefront.layouts.app')

@section('title', __('storefront_sales.cart.title'))

{{-- MB-08: sticky total and checkout button on a phone. --}}
@section('sticky_bar', '1')

@push('head')
    <meta name="robots" content="noindex, follow">
@endpush

@section('content')
    <h1 class="mb-6 text-2xl font-bold md:text-3xl">{{ __('storefront_sales.cart.title') }}</h1>
    <livewire:storefront.cart-page />
@endsection
