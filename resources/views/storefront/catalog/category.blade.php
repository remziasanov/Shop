@extends('storefront.layouts.app')

@section('content')
    @include('storefront.partials.breadcrumbs')

    <h1 class="mb-4 text-2xl font-bold md:text-3xl">{{ $heading }}</h1>
    @include('storefront.partials.telegram-links', ['param' => 'c_'.$category->id, 'shareName' => $heading, 'class' => '-mt-2 mb-4 inline-flex min-h-11 items-center gap-2 rounded-btn border border-line-strong px-4 text-sm font-medium hover:bg-surface-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary'])

    @if ($description !== '')
        <div class="prose-content mb-6 max-w-3xl space-y-3 text-ink-soft [&_a]:text-primary [&_a]:underline [&_ol]:list-decimal [&_ol]:pl-5 [&_ul]:list-disc [&_ul]:pl-5">{!! $description !!}</div>
    @endif

    @include('storefront.partials.category-tiles', ['headingId' => 'subcategories'])

    @include('storefront.partials.listing')
@endsection
