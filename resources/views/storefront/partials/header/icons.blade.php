{{-- Right-hand cluster of the header: phone, account, cart. $compact: icon-only account (H2, H3, H4);
     $phone: show the phone link (not in H3, where it sits in the utility strip); $searchToggle: search icon (H2, H4). --}}
@php
    $compact = $compact ?? false;
    $phone = $phone ?? true;
    $searchToggle = $searchToggle ?? false;
    $iconBtn = 'inline-flex h-11 w-11 items-center justify-center rounded-btn hover:bg-surface-3 active:opacity-80 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary motion-reduce:transition-none';
@endphp
@if ($phone && $shopPhones !== [])
    <a href="tel:{{ preg_replace('/[^\d+]/', '', $shopPhones[0]) }}" class="hidden text-sm font-medium md:inline" aria-label="{{ __('storefront.header.phone') }}">{{ $shopPhones[0] }}</a>
@endif
@if ($searchToggle && $searchEnabled)
    {{-- Without JavaScript the icon is a plain link to the search page. --}}
    <a href="{{ route('search') }}" class="{{ $iconBtn }} max-md:hidden" data-search-toggle aria-label="{{ __('storefront_blocks.header.search_open') }}"
       aria-controls="header-search-panel" :aria-expanded="search.toString()" aria-expanded="false"
       @click.prevent="search = ! search; if (search) $nextTick(() => document.getElementById('header-search').focus())">
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.3-4.3M10.5 18a7.5 7.5 0 100-15 7.5 7.5 0 000 15z"/></svg>
    </a>
@endif
@if ($accountLinks)
    @if ($accountCustomerName !== null)
        {{-- CA-19: the customer's name with a small menu. --}}
        <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
            <button type="button" class="flex min-w-11 max-w-40 items-center justify-center gap-1 rounded-btn px-2 py-1 text-sm font-medium hover:bg-surface-3 active:opacity-80 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary motion-reduce:transition-none"
                    aria-haspopup="true" aria-label="{{ __('account.header.account_menu') }}" :aria-expanded="open.toString()" aria-expanded="false" @click="open = ! open" data-account-menu>
                <svg class="h-5 w-5 flex-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5.5 20a6.5 6.5 0 0113 0M12 12a4 4 0 100-8 4 4 0 000 8z"/></svg>
                @unless ($compact)
                    <span class="hidden truncate md:inline">{{ $accountCustomerName }}</span>
                @endunless
                @if ($accountUnread > 0)
                    <span class="rounded-full bg-primary px-1.5 text-xs font-medium text-on-primary" data-unread-badge><span class="sr-only">{{ __('account.header.unread') }}</span>{{ $accountUnread }}</span>
                @endif
            </button>
            <ul x-cloak x-show="open" class="absolute right-0 top-full z-40 mt-1 min-w-48 rounded-btn border border-line bg-surface py-1 text-sm shadow-lg">
                <li><a href="{{ route('account') }}" class="block px-4 py-2 hover:bg-surface-2 focus-visible:bg-surface-2">{{ __('account.header.account') }}</a></li>
                <li><a href="{{ route('customer.orders') }}" class="block px-4 py-2 hover:bg-surface-2 focus-visible:bg-surface-2">{{ __('account.header.orders') }}</a></li>
                <li><a href="{{ route('customer.notifications') }}" class="block px-4 py-2 hover:bg-surface-2 focus-visible:bg-surface-2">{{ __('account.header.notifications') }}@if ($accountUnread > 0) <span class="ml-1 rounded-full bg-primary px-1.5 text-xs font-medium text-on-primary">{{ $accountUnread }}</span>@endif</a></li>
                <li><a href="{{ route('customer.profile') }}" class="block px-4 py-2 hover:bg-surface-2 focus-visible:bg-surface-2">{{ __('account.header.profile') }}</a></li>
                <li>
                    <form method="POST" action="{{ route('customer.logout') }}">
                        @csrf
                        <button type="submit" class="block w-full px-4 py-2 text-left hover:bg-surface-2 focus-visible:bg-surface-2">{{ __('account.actions.logout') }}</button>
                    </form>
                </li>
            </ul>
        </div>
    @elseif ($compact)
        <a href="{{ route('customer.login') }}" class="{{ $iconBtn }}" data-account-login aria-label="{{ __('account.header.login') }}">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5.5 20a6.5 6.5 0 0113 0M12 12a4 4 0 100-8 4 4 0 000 8z"/></svg>
        </a>
    @else
        <a href="{{ route('customer.login') }}" class="inline-flex min-h-11 items-center rounded-btn px-2 text-sm font-medium hover:bg-surface-3 active:opacity-80 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary motion-reduce:transition-none" data-account-login>{{ __('account.header.login') }}</a>
    @endif
@endif
@if ($cartEnabled)
    <livewire:storefront.mini-cart variant="header" />
@endif
