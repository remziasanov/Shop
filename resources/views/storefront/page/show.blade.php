@extends('storefront.layouts.app')

@section('title', $pageTitle)

@if ($metaDescription !== '')
    @section('meta_description', $metaDescription)
@endif

@push('head')
    <link rel="canonical" href="{{ route('page.show', $page->slug) }}">
@endpush

@section('content')
    @include('storefront.partials.breadcrumbs')

    <article class="max-w-3xl">
        <h1 class="text-2xl font-semibold">{{ $page->title }}</h1>
        <div class="mt-4 space-y-3 text-slate-700 [&_a]:text-primary [&_a]:underline [&_blockquote]:border-l-4 [&_blockquote]:border-slate-300 [&_blockquote]:pl-4 [&_h2]:mt-6 [&_h2]:text-xl [&_h2]:font-semibold [&_h3]:text-lg [&_h3]:font-semibold [&_img]:max-w-full [&_ol]:list-decimal [&_ol]:pl-5 [&_table]:w-full [&_td]:border [&_td]:p-2 [&_th]:border [&_th]:p-2 [&_ul]:list-disc [&_ul]:pl-5">{!! $content !!}</div>
    </article>
@endsection
