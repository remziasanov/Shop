@if ($labels !== [])
    <ul class="flex flex-wrap gap-1 {{ $class ?? '' }}">
        @foreach ($labels as $label)
            <li class="rounded px-2 py-0.5 text-xs font-semibold {{ ['hit' => 'bg-amber-500 text-slate-900', 'new' => 'bg-emerald-700 text-white', 'sale' => 'bg-red-600 text-white'][$label['key']] ?? 'bg-slate-600 text-white' }}">{{ $label['text'] }}</li>
        @endforeach
    </ul>
@endif
