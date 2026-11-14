{{-- GP-10: «Цены при покупке оптом» for the viewer's group only (the page never carries the tiers of another group, 21.7). Plain card: rendered on the server, highlighted by Alpine `tierPrice`; card with variants: the tiers of the selected variant (Alpine `variantPicker`). $view: ProductView, $picker: VariantPickerData|null, $unit: unit of measure. --}}
@if ($picker === null && $view->tiers !== [])
    <section class="mt-4 max-w-sm rounded-card border border-line p-3" data-tier-table aria-labelledby="tier-table-heading">
        <h2 id="tier-table-heading" class="font-body text-sm font-semibold">{{ __('group_prices.table.title') }}</h2>
        <table class="mt-2 w-full text-sm">
            <thead class="sr-only">
                <tr>
                    <th scope="col">{{ __('group_prices.table.qty') }}</th>
                    <th scope="col">{{ __('group_prices.table.price', ['unit' => $unit]) }}</th>
                    <th scope="col">{{ __('group_prices.table.saving') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr :class="qty < {{ $view->tiers[0]['min'] }} ? 'font-semibold text-primary' : ''" data-tier-row="1">
                    <td class="py-0.5">{{ __('group_prices.table.from', ['qty' => 1, 'unit' => $unit]) }}</td>
                    <td class="py-0.5">{{ \App\Support\Money::format($view->unitKopecks ?? 0) }}</td>
                    <td></td>
                </tr>
                @foreach ($view->tiers as $i => $tier)
                    <tr :class="qty >= {{ $tier['min'] }}@if (isset($view->tiers[$i + 1])) && qty < {{ $view->tiers[$i + 1]['min'] }}@endif ? 'font-semibold text-primary' : ''" data-tier-row="{{ $tier['min'] }}">
                        <td class="py-0.5">{{ __('group_prices.table.from', ['qty' => $tier['min'], 'unit' => $unit]) }}</td>
                        <td class="py-0.5">{{ $tier['price'] }}</td>
                        <td class="py-0.5 text-success">@if ($tier['saving'] > 0){{ __('group_prices.table.saving_percent', ['percent' => $tier['saving']]) }}@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="mt-2 text-sm" x-cloak x-show="qty > 1" data-tier-total aria-live="polite">
            {{ __('group_prices.table.unit_price', ['unit' => $unit]) }}: <span class="font-semibold" x-text="format(unit())"></span>
            · {{ __('group_prices.table.line_total') }}: <span class="font-semibold" x-text="format(total())"></span>
        </p>
    </section>
@elseif ($picker !== null)
    <section class="mt-4 max-w-sm rounded-card border border-line p-3" data-tier-table aria-labelledby="tier-table-heading" x-cloak x-show="current && current.t.length > 0">
        <h2 id="tier-table-heading" class="font-body text-sm font-semibold">{{ __('group_prices.table.title') }}</h2>
        <table class="mt-2 w-full text-sm">
            <tbody>
                <tr :class="current && current.t.length && qty < current.t[0][0] ? 'font-semibold text-primary' : ''" data-tier-row="1">
                    <td class="py-0.5">{{ __('group_prices.table.from', ['qty' => 1, 'unit' => $unit]) }}</td>
                    <td class="py-0.5" x-text="current && current.k !== null ? format(current.k) : ''"></td>
                    <td></td>
                </tr>
                <template x-for="(tier, i) in (current ? current.t : [])" :key="tier[0]">
                    <tr :class="qty >= tier[0] && (!current.t[i + 1] || qty < current.t[i + 1][0]) ? 'font-semibold text-primary' : ''" :data-tier-row="tier[0]">
                        <td class="py-0.5" x-text="@js(__('group_prices.table.from', ['qty' => ':q', 'unit' => $unit])).replace(':q', tier[0])"></td>
                        <td class="py-0.5" x-text="format(tier[1])"></td>
                        <td class="py-0.5 text-success" x-text="current.k > tier[1] ? @js(__('group_prices.table.saving_percent', ['percent' => ':p'])).replace(':p', Math.round((current.k - tier[1]) * 100 / current.k)) : ''"></td>
                    </tr>
                </template>
            </tbody>
        </table>
        <p class="mt-2 text-sm" x-cloak x-show="qty > 1" data-tier-total aria-live="polite">
            {{ __('group_prices.table.unit_price', ['unit' => $unit]) }}: <span class="font-semibold" x-text="unitKop() === null ? '' : format(unitKop())"></span>
            · {{ __('group_prices.table.line_total') }}: <span class="font-semibold" x-text="totalKop() === null ? '' : format(totalKop())"></span>
        </p>
    </section>
@endif
