@extends('errors._layout')

@section('title', __('seo.errors.closed.title'))

@section('content')
    <h1 class="text-2xl font-bold md:text-3xl">{{ __('seo.errors.closed.title') }}</h1>
    <p class="mt-4 whitespace-pre-line text-lg text-slate-700">{{ ($message ?? '') !== '' ? $message : __('seo.errors.closed.text') }}</p>
@endsection
