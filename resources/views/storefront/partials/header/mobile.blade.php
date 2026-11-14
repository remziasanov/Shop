{{-- Mobile menu (burger), MB-10 contacts and MB-22 install button: the same for every header variant. --}}
@if ($menuCategories !== [])
    <nav id="mobile-menu" x-cloak x-show="menu" class="border-t border-line md:hidden" aria-label="{{ __('storefront.header.categories') }}">
        <ul class="shop-touch-list px-4 py-2 text-sm">
            @foreach ($menuCategories as $item)
                <li class="py-1">
                    <a href="{{ $item['url'] }}" class="font-medium">{{ $item['name'] }}</a>
                    @if ($item['children'] !== [])
                        <ul class="ml-4 border-l border-line pl-3">
                            @foreach ($item['children'] as $child)
                                <li><a href="{{ $child['url'] }}" class="text-ink-mid">{{ $child['name'] }}</a></li>
                            @endforeach
                        </ul>
                    @endif
                </li>
            @endforeach
        </ul>
    </nav>
@endif

{{-- MB-10: phone and messenger buttons in the mobile menu. --}}
@if ($shopPhones !== [] || $telegramBotUrl || $whatsappUrl)
    <div x-cloak x-show="menu" class="border-t border-line px-4 py-3 md:hidden" data-mobile-contacts>
        <p class="mb-2 text-sm font-semibold">{{ __('mobile.contacts.menu_title') }}</p>
        <ul class="flex flex-wrap gap-2 text-sm font-medium">
            @if ($shopPhones !== [])
                <li><a href="tel:{{ preg_replace('/[^\d+]/', '', $shopPhones[0]) }}" class="inline-flex min-h-11 items-center rounded-btn border border-primary px-4 text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" data-contact="phone">{{ __('mobile.contacts.call') }}: {{ $shopPhones[0] }}</a></li>
            @endif
            @if ($telegramBotUrl)
                <li><a href="{{ $telegramBotUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-11 items-center rounded-btn border border-line-strong px-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" data-contact="telegram">{{ __('mobile.contacts.telegram') }}</a></li>
            @endif
            @if ($whatsappUrl)
                <li><a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-11 items-center rounded-btn border border-line-strong px-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" data-contact="whatsapp">{{ __('mobile.contacts.whatsapp') }}</a></li>
            @endif
        </ul>
    </div>
@endif
{{-- MB-22: «Установить приложение» in the mobile menu (renders nothing unless the PWA hint is on). --}}
<div x-cloak x-show="menu" class="px-4 pb-2 md:hidden" data-mobile-install>
    @include('storefront.partials.install-button')
</div>
