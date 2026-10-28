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

        {{-- Sections of the cabinet. The cabinet stages (orders, addresses, company, notifications) add their links here. --}}
        <nav class="mt-8" aria-label="{{ __('account.index.sections') }}">
            <ul class="grid gap-3 sm:grid-cols-2">
                <li>
                    <a href="{{ route('customer.profile') }}" class="block rounded-lg border border-slate-200 p-4 hover:border-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                        <span class="block font-semibold">{{ __('account.profile.title') }}</span>
                        <span class="block text-sm text-slate-600">{{ __('account.index.profile_hint') }}</span>
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
