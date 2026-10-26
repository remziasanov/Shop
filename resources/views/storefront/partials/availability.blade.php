@if ($availability)
    <p class="flex items-center gap-1.5 text-sm {{ ['in' => 'text-emerald-700', 'low' => 'text-amber-700', 'out' => 'text-slate-500'][$availability['state']] ?? '' }}">
        <span class="h-2 w-2 rounded-full {{ ['in' => 'bg-emerald-600', 'low' => 'bg-amber-500', 'out' => 'bg-slate-400'][$availability['state']] ?? '' }}" aria-hidden="true"></span>
        {{ $availability['text'] }}
    </p>
@endif
