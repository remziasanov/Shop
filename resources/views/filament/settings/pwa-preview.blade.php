@php
    /** @var array{icon: ?string, name: string, background: string} $preview */
@endphp
<div class="space-y-2" data-pwa-preview>
    <p class="text-sm font-medium text-gray-950 dark:text-white">{{ __('pwa.admin.preview') }}</p>
    <div class="relative mx-auto flex h-64 w-36 flex-col items-center rounded-[1.75rem] border-4 border-gray-800 bg-gradient-to-b from-sky-200 to-indigo-300 p-3 shadow-lg">
        <div class="mb-6 h-1.5 w-12 rounded-full bg-gray-800/60"></div>
        <div class="flex w-16 flex-col items-center gap-1">
            @if ($preview['icon'])
                <img src="{{ $preview['icon'] }}" alt="" width="56" height="56" class="h-14 w-14 rounded-2xl object-cover shadow" style="background-color: {{ $preview['background'] }}">
            @else
                <div class="flex h-14 w-14 items-center justify-center rounded-2xl text-center text-[10px] leading-tight text-gray-600" style="background-color: {{ $preview['background'] }}">{{ __('pwa.admin.preview_empty') }}</div>
            @endif
            <span class="w-full truncate text-center text-[11px] font-medium text-gray-900">{{ $preview['name'] }}</span>
        </div>
    </div>
</div>
