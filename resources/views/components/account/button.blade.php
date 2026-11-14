@props(['variant' => 'primary'])
<button {{ $attributes->merge(['type' => 'submit', 'class' => ($variant === 'primary'
    ? 'rounded-btn bg-primary px-6 py-2 font-medium text-on-primary hover:opacity-90'
    : 'rounded-btn border border-line-strong px-4 py-2 hover:bg-surface-2').' focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary']) }}>{{ $slot }}</button>
