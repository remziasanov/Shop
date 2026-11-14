{{-- Action buttons of a card (shared by C1…C6): cart = plain POST form enhanced by Alpine `cardAdd` (NF-02), «Выбрать» for a card with variants (VR-08), reservation / inquiry open the product page with the modal. $view; $cardActions (already without the MR-03 duplicate); $style: c1 (full-width buttons) | link (C2, underlined text) | round (C3, «+» circle) | compact (inline button). --}}
@php
    $focus = 'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';
    $s = [
        'c1' => [
            'cart' => 'w-full rounded-btn bg-primary px-3 py-2 text-center text-sm font-medium text-on-primary hover:opacity-90 active:opacity-80 disabled:opacity-60',
            'select' => 'flex min-h-11 items-center justify-center rounded-btn bg-primary px-3 py-2 text-center text-sm font-medium text-on-primary hover:opacity-90 active:opacity-80',
            'link' => 'flex min-h-11 items-center justify-center rounded-btn px-3 py-2 text-center text-sm font-medium',
            'primary' => 'bg-primary text-on-primary hover:opacity-90 active:opacity-80',
            'secondary' => 'border border-primary text-primary hover:bg-surface-2 active:bg-surface-3',
            'disabled' => 'flex min-h-11 cursor-not-allowed items-center justify-center rounded-btn bg-line px-3 py-2 text-center text-sm font-medium text-muted',
            'form' => '',
        ],
        'compact' => [
            'cart' => 'min-h-11 whitespace-nowrap rounded-btn bg-primary px-4 py-2 text-center text-sm font-medium text-on-primary hover:opacity-90 active:opacity-80 disabled:opacity-60',
            'select' => 'flex min-h-11 items-center justify-center whitespace-nowrap rounded-btn bg-primary px-4 py-2 text-center text-sm font-medium text-on-primary hover:opacity-90 active:opacity-80',
            'link' => 'flex min-h-11 items-center justify-center whitespace-nowrap rounded-btn px-4 py-2 text-center text-sm font-medium',
            'primary' => 'bg-primary text-on-primary hover:opacity-90 active:opacity-80',
            'secondary' => 'border border-primary text-primary hover:bg-surface-2 active:bg-surface-3',
            'disabled' => 'flex min-h-11 cursor-not-allowed items-center justify-center whitespace-nowrap rounded-btn bg-surface-3 px-4 py-2 text-center text-sm font-medium text-muted',
            'form' => '',
        ],
        'link' => [
            'cart' => 'inline-flex min-h-11 items-center whitespace-nowrap text-sm font-medium underline underline-offset-4 hover:text-primary active:text-primary disabled:opacity-60',
            'select' => 'inline-flex min-h-11 items-center whitespace-nowrap text-sm font-medium underline underline-offset-4 hover:text-primary active:text-primary',
            'link' => 'inline-flex min-h-11 items-center whitespace-nowrap text-sm font-medium underline underline-offset-4',
            'primary' => 'hover:text-primary active:text-primary',
            'secondary' => 'text-ink-mid hover:text-primary active:text-primary',
            'disabled' => 'inline-flex min-h-11 cursor-not-allowed items-center whitespace-nowrap text-sm text-muted',
            'form' => '',
        ],
        'round' => [
            'cart' => 'flex h-11 w-11 flex-none items-center justify-center rounded-full bg-primary text-on-primary hover:opacity-90 active:opacity-80 disabled:opacity-60',
            'select' => 'flex min-h-11 items-center justify-center rounded-full bg-primary px-4 py-2 text-center text-sm font-medium text-on-primary hover:opacity-90 active:opacity-80',
            'link' => 'flex min-h-11 items-center justify-center rounded-full px-4 py-2 text-center text-sm font-medium',
            'primary' => 'bg-primary text-on-primary hover:opacity-90 active:opacity-80',
            'secondary' => 'border border-primary text-primary hover:bg-surface-2 active:bg-surface-3',
            'disabled' => 'flex min-h-11 cursor-not-allowed items-center justify-center rounded-full bg-surface-3 px-4 py-2 text-center text-sm font-medium text-muted',
            'form' => 'flex-none',
        ],
    ][$style ?? 'c1'];
    // Variants other than C1 keep a disabled button readable on themes where the line color is dark.
    $s['disabled'] = ($soft ?? false) ? str_replace('bg-line ', 'bg-surface-3 ', $s['disabled']) : $s['disabled'];
    $round = ($style ?? 'c1') === 'round';
@endphp
@foreach ($cardActions as $action)
    @if ($action['disabled'])
        <span aria-disabled="true" class="{{ $s['disabled'] }}">{{ $action['label'] }}</span>
    @elseif ($action['key'] === 'cart')
        <form method="post" action="{{ route('cart.add', [], false) }}" x-data="cardAdd" @submit.prevent="submit($event)" data-error="{{ __('sales.guard.generic') }}" class="{{ $s['form'] }}">
            @csrf
            <input type="hidden" name="product_id" value="{{ $view->product->id }}">
            <button type="submit" data-action="cart" :disabled="busy" :aria-busy="busy.toString()" class="{{ $s['cart'] }} {{ $focus }} @if ($round && ($action['reserve'] ?? false)) group/res relative @endif" @if ($round) title="{{ $action['label'] }}" @endif>
                @if ($round && ($action['reserve'] ?? false))
                    {{-- Reservation row: a bookmark instead of «+», and the caption shows on hover / focus so it is not mistaken for «В корзину». --}}
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true" data-reserve-icon><path stroke-linecap="round" stroke-linejoin="round" d="M6 4h12v17l-6-4-6 4z"/></svg>
                    <span class="pointer-events-none absolute bottom-full right-0 z-10 mb-1 hidden whitespace-nowrap rounded-btn bg-ink px-2 py-1 text-xs font-medium text-bg group-hover/res:block group-focus-visible/res:block" aria-hidden="true" data-reserve-caption>{{ $action['label'] }}</span>
                    <span class="sr-only">{{ $action['label'] }}: {{ $view->product->name }}</span>
                @elseif ($round)
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/></svg>
                    <span class="sr-only">{{ $action['label'] }}: {{ $view->product->name }}</span>
                @else
                    {{ $action['label'] }}<span class="sr-only">: {{ $view->product->name }}</span>
                @endif
            </button>
        </form>
    @elseif ($action['key'] === 'select')
        {{-- VR-08: a card with variants cannot be bought from a list; «Выбрать» opens the card. --}}
        <a href="{{ $view->url }}" data-action="select" class="{{ $s['select'] }} {{ $focus }}">{{ $action['label'] }}<span class="sr-only">: {{ $view->product->name }}</span></a>
    @else
        <a href="{{ $view->url }}{{ str_contains($view->url, '?') ? '&' : '?' }}{{ $action['key'] === 'inquiry' ? 'inquiry' : 'reserve' }}=1" data-action="{{ $action['key'] }}" class="{{ $s['link'] }} {{ $focus }} {{ $action['primary'] ? $s['primary'] : $s['secondary'] }}">{{ ($action['single'] ?? false) ? __('storefront.actions.reservation') : $action['label'] }}<span class="sr-only">: {{ $view->product->name }}</span></a>
    @endif
@endforeach
