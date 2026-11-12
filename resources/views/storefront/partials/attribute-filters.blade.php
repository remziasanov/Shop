{{-- Characteristic filters of the category (FL-13): list values with counters, number range, boolean. Names follow FL-07. --}}
@foreach ($facets as $group)
    @php($open = $group->expanded || $group->hasSelection())
    <details class="border-t border-slate-200 pt-1" @if ($open) open @endif>
        <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between text-sm font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary [&::-webkit-details-marker]:hidden">
            <span>{{ $group->name }}@if ($group->unit), {{ $group->unit }}@endif</span>
            <svg class="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
        </summary>

        @if ($group->type === \App\Enums\AttributeType::List)
            <div class="max-h-64 overflow-y-auto pb-2">
                @foreach ($group->values as $value)
                    <label class="flex min-h-11 items-center gap-2 text-sm">
                        <input type="checkbox" name="a[{{ $group->slug }}][]" value="{{ $value->slug }}" @checked($value->selected) class="h-5 w-5 flex-none rounded border-slate-300 accent-primary">
                        <span class="min-w-0 flex-1">{{ $value->label }}</span>
                        <span class="text-xs text-slate-500">{{ $value->count }}</span>
                    </label>
                @endforeach
            </div>
        @elseif ($group->type === \App\Enums\AttributeType::Number)
            <div class="flex items-center gap-2 pb-2">
                <label for="a-{{ $group->slug }}-min" class="sr-only">{{ __('storefront.filters.from', ['name' => $group->name]) }}</label>
                <input id="a-{{ $group->slug }}-min" name="a[{{ $group->slug }}][min]" type="text" inputmode="decimal" value="{{ $group->selectedMin }}" placeholder="{{ $group->min ?? __('storefront.filters.price_from') }}"
                       class="min-h-11 w-full rounded border border-slate-300 px-2 py-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                <span aria-hidden="true">–</span>
                <label for="a-{{ $group->slug }}-max" class="sr-only">{{ __('storefront.filters.to', ['name' => $group->name]) }}</label>
                <input id="a-{{ $group->slug }}-max" name="a[{{ $group->slug }}][max]" type="text" inputmode="decimal" value="{{ $group->selectedMax }}" placeholder="{{ $group->max ?? __('storefront.filters.price_to') }}"
                       class="min-h-11 w-full rounded border border-slate-300 px-2 py-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
            </div>
        @else
            <label class="flex min-h-11 items-center gap-2 pb-1 text-sm">
                <input type="checkbox" name="a[{{ $group->slug }}]" value="1" @checked($group->checked) class="h-5 w-5 flex-none rounded border-slate-300 accent-primary">
                {{ $group->name }}
            </label>
        @endif
    </details>
@endforeach
