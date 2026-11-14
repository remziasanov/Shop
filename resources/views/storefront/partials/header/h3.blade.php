{{-- TH-10, H3: utility strip, «Каталог» button, wide search. Below 768 px: menu, logo, cart and the search field under them. --}}
@php($hasContacts = $shopPhones !== [] || $telegramBotUrl || $whatsappUrl)
@if ($footerMenu !== [] || $hasContacts)
    <div class="hidden border-b border-hairline bg-surface-2 text-xs text-ink-soft md:block" data-header-utility>
        <div class="mx-auto flex w-full max-w-7xl items-center gap-x-5 px-4">
            @if ($footerMenu !== [])
                <ul class="flex flex-wrap gap-x-5" aria-label="{{ __('storefront_blocks.header.utility') }}">
                    @foreach (array_slice($footerMenu, 0, 4) as $item)
                        <li><a href="{{ $item['url'] }}" class="inline-flex min-h-8 items-center hover:text-primary hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ $item['label'] }}</a></li>
                    @endforeach
                </ul>
            @endif
            @if ($hasContacts)
                <ul class="ml-auto flex flex-wrap gap-x-5">
                    @if ($shopPhones !== [])
                        <li><a href="tel:{{ preg_replace('/[^\d+]/', '', $shopPhones[0]) }}" class="inline-flex min-h-8 items-center font-medium hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" aria-label="{{ __('storefront.header.phone') }}">{{ $shopPhones[0] }}</a></li>
                    @endif
                    @if ($telegramBotUrl)
                        <li><a href="{{ $telegramBotUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-8 items-center hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" data-header-contact="telegram">{{ __('mobile.contacts.telegram') }}</a></li>
                    @endif
                    @if ($whatsappUrl)
                        <li><a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-8 items-center hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" data-header-contact="whatsapp">{{ __('mobile.contacts.whatsapp') }}</a></li>
                    @endif
                </ul>
            @endif
        </div>
    </div>
@endif
<div class="mx-auto flex w-full max-w-7xl flex-wrap items-center gap-3 px-4 py-3">
    @include('storefront.partials.header.burger')
    @include('storefront.partials.header.logo')

    @if ($menuCategories !== [])
        {{-- «Каталог»: a native disclosure (works without JavaScript); Alpine only closes it on Escape / outside click. --}}
        <details class="relative hidden md:block" data-header-catalog @click.outside="$el.removeAttribute('open')" @keydown.escape="$el.removeAttribute('open')">
            <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-btn bg-primary px-4 text-sm font-semibold text-on-primary hover:opacity-90 active:opacity-80 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none [&::-webkit-details-marker]:hidden">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h7v7H4zM13 6h7v7h-7zM4 15h7v5H4zM13 15h7v5h-7z"/></svg>
                {{ __('storefront_blocks.header.catalog') }}
            </summary>
            <nav class="absolute left-0 top-full z-40 mt-1 w-[min(56rem,calc(100vw-2rem))] rounded-card border border-line bg-surface p-4 shadow-lg" aria-label="{{ __('storefront_blocks.header.catalog_menu') }}">
                <ul class="grid gap-x-6 gap-y-3 text-sm md:grid-cols-3 lg:grid-cols-4">
                    @foreach ($menuCategories as $item)
                        <li>
                            <a href="{{ $item['url'] }}" class="block rounded-btn py-1 font-semibold hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ $item['name'] }}</a>
                            @if ($item['children'] !== [])
                                <ul class="mt-1 space-y-0.5 text-ink-soft">
                                    @foreach ($item['children'] as $child)
                                        <li><a href="{{ $child['url'] }}" class="block rounded-btn py-0.5 hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ $child['name'] }}</a></li>
                                    @endforeach
                                </ul>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </nav>
        </details>
    @elseif ($catalogEnabled)
        <a href="{{ route('catalog.index') }}" class="hidden min-h-11 items-center rounded-btn bg-primary px-4 text-sm font-semibold text-on-primary hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary md:inline-flex">{{ __('storefront_blocks.header.catalog') }}</a>
    @endif

    @if ($searchEnabled)
        @include('storefront.partials.header.search', ['class' => 'relative order-last w-full md:order-none md:flex-1'])
    @endif
    <div class="ml-auto flex items-center gap-1 md:ml-0 md:gap-2">
        @include('storefront.partials.header.icons', ['compact' => true, 'phone' => false])
    </div>
</div>
