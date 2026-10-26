@extends('storefront.layouts.app')

@section('content')
    @include('storefront.partials.breadcrumbs')

    <h1 class="mb-4 text-2xl font-bold md:text-3xl">{{ $category->name }}</h1>

    @if ($description !== '')
        <div class="prose-content mb-6 max-w-3xl space-y-3 text-slate-700 [&_a]:text-primary [&_a]:underline [&_ol]:list-decimal [&_ol]:pl-5 [&_ul]:list-disc [&_ul]:pl-5">{!! $description !!}</div>
    @endif

    @include('storefront.partials.category-tiles', ['headingId' => 'subcategories'])

    @include('storefront.partials.listing')
@endsection
