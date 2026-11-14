@extends('storefront.layouts.telegram')

@section('title', __('telegram_staff.workplace.title'))
@section('staff', '1')
@section('no_bottom_nav', '1')

@section('content')
    <livewire:staff.workplace-list />
@endsection
