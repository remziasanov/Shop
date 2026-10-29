@extends('storefront.layouts.app')

@section('content')
    <div class="mx-auto w-full max-w-2xl py-6">
        <p class="text-sm"><a href="{{ route('account') }}" class="text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('account.index.title') }}</a></p>
        <h1 class="mt-2 text-2xl font-bold md:text-3xl">{{ __('account.addresses.title') }}</h1>
        <p class="mt-1 text-sm text-slate-600">{{ __('account.addresses.lead', ['max' => $max]) }}</p>
        <div class="mt-4">
            <x-account.errors />
        </div>

        @if ($addresses->isEmpty())
            <p class="mt-4 rounded-lg border border-slate-200 p-4 text-slate-600" data-addresses-empty>{{ __('account.addresses.empty') }}</p>
        @else
            <ul class="mt-4 space-y-3">
                @foreach ($addresses as $item)
                    <li class="rounded-lg border border-slate-200 p-4" data-address>
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <p class="font-semibold">
                                    {{ $item->label }}
                                    @if ($item->is_default)
                                        <span class="ml-2 rounded bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">{{ __('account.addresses.default_badge') }}</span>
                                    @endif
                                </p>
                                <p class="text-sm text-slate-700">{{ $item->recipient_name }}, {{ $item->phone }}</p>
                                <p class="whitespace-pre-line text-sm text-slate-700">{{ $item->address }}</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @unless ($item->is_default)
                                    <form method="POST" action="{{ route('customer.addresses.default', $item->id) }}">
                                        @csrf
                                        <x-account.button variant="secondary" class="text-sm">{{ __('account.addresses.make_default') }}</x-account.button>
                                    </form>
                                @endunless
                                <form method="POST" action="{{ route('customer.addresses.destroy', $item->id) }}">
                                    @csrf
                                    <x-account.button variant="secondary" class="text-sm">{{ __('account.addresses.delete') }}</x-account.button>
                                </form>
                            </div>
                        </div>

                        <details class="mt-3">
                            <summary class="cursor-pointer text-sm text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('account.addresses.edit') }}</summary>
                            <form method="POST" action="{{ route('customer.addresses.update', $item->id) }}" class="mt-3 space-y-4" novalidate>
                                @csrf
                                <x-account.field :prefix="'ae'.$item->id" name="label" :label="__('account.addresses.fields.label')" :value="$item->label" maxlength="100" />
                                <x-account.field :prefix="'ae'.$item->id" name="recipient_name" :label="__('account.addresses.fields.recipient_name')" :value="$item->recipient_name" autocomplete="name" maxlength="150" />
                                <x-account.field :prefix="'ae'.$item->id" name="phone" type="tel" :label="__('account.addresses.fields.phone')" :value="$item->phone" autocomplete="tel" inputmode="tel"
                                                 x-data="phoneMask" x-on:input="format($event)" />
                                <x-account.field :prefix="'ae'.$item->id" name="address" :label="__('account.addresses.fields.address')" :value="$item->address" autocomplete="street-address" maxlength="500" />
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" name="is_default" value="1" @checked($item->is_default) class="h-4 w-4 rounded border-slate-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                                    {{ __('account.addresses.fields.is_default') }}
                                </label>
                                <x-account.button>{{ __('account.addresses.save') }}</x-account.button>
                            </form>
                        </details>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($addresses->count() < $max)
            <section class="mt-8 border-t border-slate-200 pt-6" aria-labelledby="address-add-title">
                <h2 id="address-add-title" class="text-lg font-semibold">{{ __('account.addresses.add') }}</h2>
                <form method="POST" action="{{ route('customer.addresses.store') }}" class="mt-3 space-y-4" novalidate>
                    @csrf
                    <x-account.field prefix="an" name="label" :label="__('account.addresses.fields.label')" maxlength="100" :hint="__('account.addresses.label_hint')" />
                    <x-account.field prefix="an" name="recipient_name" :label="__('account.addresses.fields.recipient_name')" autocomplete="name" maxlength="150" />
                    <x-account.field prefix="an" name="phone" type="tel" :label="__('account.addresses.fields.phone')" autocomplete="tel" inputmode="tel"
                                     x-data="phoneMask" x-on:input="format($event)" />
                    <x-account.field prefix="an" name="address" :label="__('account.addresses.fields.address')" autocomplete="street-address" maxlength="500" />
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="is_default" value="1" @checked(old('is_default')) class="h-4 w-4 rounded border-slate-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                        {{ __('account.addresses.fields.is_default') }}
                    </label>
                    <x-account.button>{{ __('account.addresses.add_submit') }}</x-account.button>
                </form>
            </section>
        @else
            <p class="mt-6 text-sm text-slate-600">{{ __('account.addresses.limit', ['max' => $max]) }}</p>
        @endif
    </div>
@endsection
