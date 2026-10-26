{{-- Home page advantages block (5.2): icon + title + text. --}}
<section class="mb-8" aria-label="{{ __('home.storefront.advantages') }}">
    <ul class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($items as $item)
            <li class="flex items-start gap-3 rounded-lg border border-slate-200 p-4">
                <span class="flex-none text-primary">{!! \App\Services\Home\HomeIcons::svg($item['icon']) !!}</span>
                <span class="min-w-0">
                    <span class="block font-semibold leading-snug">{{ $item['title'] }}</span>
                    @if ($item['text'])
                        <span class="mt-1 block text-sm text-slate-600">{{ $item['text'] }}</span>
                    @endif
                </span>
            </li>
        @endforeach
    </ul>
</section>
