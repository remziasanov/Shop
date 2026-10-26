@extends('storefront.layouts.app')

@section('title', __('storefront.home.title'))

@section('content')
    <h1 class="sr-only">{{ $shopName }}</h1>
    {{-- Rendered and cached by HomePageRenderer (D-054); content comes from the builder, HTML blocks are admin-authored. --}}
    {!! $blocksHtml !!}
@endsection
