@if ($labels !== [])
    <ul class="flex flex-wrap gap-1 {{ $class ?? '' }}">
        @foreach ($labels as $label)
            <li class="rounded-btn px-2 py-0.5 text-xs font-semibold {{ ['hit' => 'bg-accent text-on-accent', 'new' => 'bg-success text-on-solid', 'sale' => 'bg-danger-solid text-on-solid'][$label['key']] ?? 'bg-ink-mid text-bg' }}">{{ $label['text'] }}</li>
        @endforeach
    </ul>
@endif
