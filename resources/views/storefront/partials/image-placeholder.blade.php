{{-- Placeholder for a product without photo. $url: configured placeholder (AppearanceSettings) or null for the inline SVG. --}}
@if (! empty($url))
    <img src="{{ $url }}" alt="{{ $alt ?? '' }}" width="400" height="400" loading="lazy" decoding="async" class="h-full w-full object-contain">
@else
    <svg class="h-full w-full text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" @if (($alt ?? null) === '') aria-hidden="true" @else role="img" aria-label="{{ $alt ?? __('storefront.product.no_photo') }}" @endif>
        <rect x="3" y="4" width="18" height="16" rx="2"/>
        <circle cx="9" cy="10" r="1.5"/>
        <path stroke-linecap="round" stroke-linejoin="round" d="M3 17l5-5 4 4 3-3 6 6"/>
    </svg>
@endif
