@extends('storefront.layouts.app')

@section('title', __('storefront.home.title'))

@section('content')
    <h1 class="text-2xl font-bold md:text-3xl">{{ __('storefront.home.welcome', ['name' => $shopName]) }}</h1>
@endsection
