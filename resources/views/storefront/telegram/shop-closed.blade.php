{{-- TM-11, SF-05: the closed-shop page of the Mini App; same message as errors.shop-closed, in the Telegram layout. --}}
@extends('storefront.layouts.telegram')

@section('title', __('seo.errors.closed.title').' — '.$shopName)
@section('bare', '1')

@section('content')
    <section class="py-16 text-center" data-shop-closed>
        <h1 class="text-2xl font-bold">{{ __('seo.errors.closed.title') }}</h1>
        <p class="mt-3 whitespace-pre-line text-ink-mid">{{ ($message ?? '') !== '' ? $message : __('seo.errors.closed.text') }}</p>
    </section>
@endsection
