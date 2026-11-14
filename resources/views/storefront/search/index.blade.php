@extends('storefront.layouts.app')

@section('content')
    @include('storefront.partials.breadcrumbs', ['breadcrumbs' => [
        ['name' => __('storefront.breadcrumbs.home'), 'url' => route('home')],
        ['name' => __('storefront.search.title'), 'url' => null],
    ]])

    @if ($cards === null)
        <h1 class="mb-4 text-2xl font-bold md:text-3xl">{{ __('storefront.search.title') }}</h1>
        <p class="rounded-card border border-dashed border-line-strong p-6 text-center text-ink-mid">{{ __('storefront.search.empty_query') }}</p>
    @else
        <h1 class="mb-4 text-2xl font-bold md:text-3xl">{{ __('storefront.search.results_for', ['term' => $term]) }}</h1>
        @include('storefront.partials.listing', ['emptyText' => __('storefront.search.nothing_found', ['term' => $term])])
    @endif
@endsection
