@props(['variant' => 'primary'])
<button {{ $attributes->merge(['type' => 'submit', 'class' => ($variant === 'primary'
    ? 'rounded bg-primary px-6 py-2 font-medium text-white hover:opacity-90'
    : 'rounded border border-slate-300 px-4 py-2 hover:bg-slate-50').' focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary']) }}>{{ $slot }}</button>
