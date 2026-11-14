@extends('storefront.layouts.app')

@section('content')
    <div class="mx-auto w-full max-w-3xl py-6">
        <h1 class="text-2xl font-bold md:text-3xl">{{ __('account.index.title') }}</h1>
        <div class="mt-4">
            <x-account.errors />
        </div>

        <dl class="mt-2 grid gap-x-8 gap-y-2 text-sm sm:grid-cols-[max-content_1fr]">
            <dt class="text-slate-500">{{ __('account.fields.name') }}</dt>
            <dd class="font-medium">{{ $customer->name }}</dd>
            <dt class="text-slate-500">{{ __('account.fields.email') }}</dt>
            <dd class="font-medium">{{ $customer->email }}</dd>
            @if ($customer->phone)
                <dt class="text-slate-500">{{ __('account.fields.phone') }}</dt>
                <dd class="font-medium">{{ $customer->phone }}</dd>
            @endif
            <dt class="text-slate-500">{{ __('account.index.group') }}</dt>
            <dd class="font-medium">{{ $groupName }}</dd>
        </dl>

        {{-- MB-22: «Установить приложение» (Android / desktop), hidden unless the browser offers the install. --}}
        <div class="mt-4">@include('storefront.partials.install-button', ['class' => 'inline-flex min-h-11 items-center gap-2 rounded border border-primary px-4 py-2 text-sm font-medium text-primary hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary'])</div>

        {{-- Sections of the cabinet. Wholesale access (stage 9, part B) adds its link below the security section. --}}
        <nav class="mt-8" aria-label="{{ __('account.index.sections') }}">
            <ul class="grid gap-3 sm:grid-cols-2">
                <li>
                    <a href="{{ route('customer.profile') }}" class="block rounded-lg border border-slate-200 p-4 hover:border-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                        <span class="block font-semibold">{{ __('account.profile.title') }}</span>
                        <span class="block text-sm text-slate-600">{{ __('account.index.profile_hint') }}</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('customer.orders') }}" class="block rounded-lg border border-slate-200 p-4 hover:border-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                        <span class="block font-semibold">{{ __('account.orders.title') }}</span>
                        <span class="block text-sm text-slate-600">{{ __('account.index.orders_hint') }}</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('customer.addresses') }}" class="block rounded-lg border border-slate-200 p-4 hover:border-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                        <span class="block font-semibold">{{ __('account.addresses.title') }}</span>
                        <span class="block text-sm text-slate-600">{{ __('account.index.addresses_hint') }}</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('customer.company') }}" class="block rounded-lg border border-slate-200 p-4 hover:border-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                        <span class="block font-semibold">{{ __('account.company.title') }}</span>
                        <span class="block text-sm text-slate-600">{{ __('account.index.company_hint') }}</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('customer.notifications') }}" class="block rounded-lg border border-slate-200 p-4 hover:border-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                        <span class="block font-semibold">{{ __('account.notifications.title') }} @if ($unreadCount > 0)<span class="ml-1 rounded-full bg-primary px-2 py-0.5 text-xs font-medium text-white" data-unread-count>{{ $unreadCount }}</span>@endif</span>
                        <span class="block text-sm text-slate-600">{{ __('account.index.notifications_hint') }}</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('customer.exports') }}" class="block rounded-lg border border-slate-200 p-4 hover:border-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" data-exports-link>
                        <span class="block font-semibold">{{ __('customer_export.list.title') }}</span>
                        <span class="block text-sm text-slate-600">{{ __('customer_export.list.index_hint') }}</span>
                    </a>
                </li>
                @if (app(\App\Services\Access\ViewerContext::class)->access()->allowsSection(\App\Enums\StorefrontSection::PriceList))
                    {{-- CG-17: the wholesale price list of the customer's group. --}}
                    <li>
                        <a href="{{ route('customer.price-list') }}" class="block rounded-lg border border-slate-200 p-4 hover:border-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" data-price-list-link>
                            <span class="block font-semibold">{{ __('group_prices.price_list.title') }}</span>
                            <span class="block text-sm text-slate-600">{{ __('group_prices.price_list.download') }}</span>
                        </a>
                    </li>
                @endif
                <li>
                    <a href="{{ route('customer.security') }}" class="block rounded-lg border border-slate-200 p-4 hover:border-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                        <span class="block font-semibold">{{ __('account.security.title') }}</span>
                        <span class="block text-sm text-slate-600">{{ __('account.index.security_hint') }}</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('customer.wholesale') }}" class="block rounded-lg border border-slate-200 p-4 hover:border-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                        <span class="block font-semibold">{{ __('wholesale.page.title') }}</span>
                        <span class="block text-sm text-slate-600">{{ __('wholesale.page.menu_hint') }}</span>
                    </a>
                </li>
            </ul>
        </nav>

        <form method="POST" action="{{ route('customer.logout') }}" class="mt-8">
            @csrf
            <x-account.button variant="secondary">{{ __('account.actions.logout') }}</x-account.button>
        </form>
    </div>
@endsection
