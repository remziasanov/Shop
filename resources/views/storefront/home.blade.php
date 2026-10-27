@extends('storefront.layouts.app')

@section('title', __('storefront.home.title'))

@push('head')
    {{-- First banner image is the LCP element: start loading it before the body is parsed (NF-03). --}}
    @foreach ($preloadImages as $image)
        <link rel="preload" as="image" href="{{ $image['href'] }}" imagesrcset="{{ $image['srcset'] }}" imagesizes="{{ $image['sizes'] }}"@if ($image['media']) media="{{ $image['media'] }}"@endif>
    @endforeach
@endpush

@section('content')
    <h1 class="sr-only">{{ $shopName }}</h1>
    {{-- Rendered and cached by HomePageRenderer (D-054); content comes from the builder, HTML blocks are admin-authored. --}}
    {!! $blocksHtml !!}
@endsection
