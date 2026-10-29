@extends('storefront.layouts.app')

@section('content')
    <div class="mx-auto w-full max-w-2xl py-6">
        <p class="text-sm"><a href="{{ route('account') }}" class="text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('account.index.title') }}</a></p>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-bold md:text-3xl">{{ __('account.notifications.title') }}</h1>
            @if ($unreadTotal > 0)
                <form method="POST" action="{{ route('customer.notifications.read-all') }}">
                    @csrf
                    <x-account.button variant="secondary" class="text-sm">{{ __('account.notifications.read_all') }}</x-account.button>
                </form>
            @endif
        </div>
        <p class="mt-1 text-sm text-slate-600">{{ __('account.notifications.retention', ['days' => \App\Models\CustomerNotification::RETENTION_DAYS]) }}</p>
        <div class="mt-4">
            <x-account.errors />
        </div>

        @if ($notifications->isEmpty())
            <p class="rounded-lg border border-slate-200 p-4 text-slate-600" data-notifications-empty>{{ __('account.notifications.empty') }}</p>
        @else
            <ul class="divide-y divide-slate-200 rounded-lg border border-slate-200">
                @foreach ($notifications as $notification)
                    <li class="p-4 {{ $notification->read_at === null ? 'bg-slate-50' : '' }}" data-notification @if ($notification->read_at === null) data-unread @endif>
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <p class="{{ $notification->read_at === null ? 'font-semibold' : 'font-medium' }}">{{ $notification->title }}</p>
                                <p class="text-sm text-slate-700">{{ $notification->body }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ $notification->created_at?->format('d.m.Y H:i') }}</p>
                            </div>
                            <form method="POST" action="{{ route('customer.notifications.read', $notification->id) }}">
                                @csrf
                                <x-account.button variant="secondary" class="text-sm">
                                    {{ $notification->url ? __('account.notifications.open') : __('account.notifications.mark_read') }}
                                </x-account.button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
            {{ $notifications->links('storefront.partials.pagination') }}
        @endif
    </div>
@endsection
