@if ($availability)
    <p class="flex items-center gap-1.5 text-sm {{ ['in' => 'text-success', 'low' => 'text-warn-deep', 'out' => 'text-muted'][$availability['state']] ?? '' }}">
        <span class="h-2 w-2 rounded-full {{ ['in' => 'bg-success-solid', 'low' => 'bg-warn', 'out' => 'bg-faint'][$availability['state']] ?? '' }}" aria-hidden="true"></span>
        {{ $availability['text'] }}
    </p>
@endif
