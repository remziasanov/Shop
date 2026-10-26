@extends('errors._layout')

@section('title', __('seo.errors.503.title'))

@section('content')
    <p class="text-6xl font-bold text-primary" aria-hidden="true">503</p>
    <h1 class="mt-4 text-2xl font-bold md:text-3xl">{{ __('seo.errors.503.title') }}</h1>
    <p class="mt-3 text-slate-600">{{ __('seo.errors.503.text') }}</p>

    <div class="mt-8 flex flex-wrap justify-center gap-3">
        <a href="{{ $homeUrl }}" class="rounded bg-primary px-5 py-2 font-medium text-white hover:opacity-90">{{ __('seo.errors.home') }}</a>
        <a href="{{ $catalogUrl }}" class="rounded border border-slate-300 px-5 py-2 font-medium hover:bg-slate-50">{{ __('seo.errors.catalog') }}</a>
    </div>

    <form action="{{ $searchUrl }}" method="get" class="mx-auto mt-6 flex max-w-md gap-2" role="search">
        <label for="error-search" class="sr-only">{{ __('seo.errors.search_placeholder') }}</label>
        <input id="error-search" name="q" type="search" placeholder="{{ __('seo.errors.search_placeholder') }}" class="w-full rounded border border-slate-300 px-3 py-2">
        <button type="submit" class="rounded border border-slate-300 px-4 py-2 hover:bg-slate-50">{{ __('seo.errors.search') }}</button>
    </form>
@endsection
