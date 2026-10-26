@if ($labels !== [])
    <ul class="flex flex-wrap gap-1 {{ $class ?? '' }}">
        @foreach ($labels as $label)
            <li class="rounded px-2 py-0.5 text-xs font-semibold text-white {{ ['hit' => 'bg-amber-500', 'new' => 'bg-emerald-600', 'sale' => 'bg-red-600'][$label['key']] ?? 'bg-slate-600' }}">{{ $label['text'] }}</li>
        @endforeach
    </ul>
@endif
