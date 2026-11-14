@extends('storefront.layouts.app')

@section('title', __('storefront_sales.checkout.title'))

{{-- MB-03, MB-08: no bottom navigation here, the total and the submit button stand in a sticky bar. --}}
@section('no_bottom_nav', '1')
@section('sticky_bar', '1')

@push('head')
    <meta name="robots" content="noindex, nofollow">
@endpush

@section('content')
    <h1 class="mb-6 text-2xl font-bold md:text-3xl">{{ __('storefront_sales.checkout.title') }}</h1>
    <livewire:storefront.checkout-form />
@endsection
