@extends('storefront.layouts.app')

@section('title', __('storefront.catalog.title'))

@push('head')
    <link rel="canonical" href="{{ $canonical }}">
@endpush

@section('content')
    @include('storefront.partials.breadcrumbs')

    <h1 class="mb-4 text-2xl font-bold md:text-3xl">{{ __('storefront.catalog.title') }}</h1>

    @include('storefront.partials.category-tiles', ['headingId' => 'root-categories'])

    <h2 class="mb-3 text-xl font-semibold">{{ __('storefront.catalog.products') }}</h2>
    @include('storefront.partials.listing')
@endsection
