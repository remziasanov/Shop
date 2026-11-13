<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\OrderChangeKind;
use App\Enums\OrderType;
use App\Support\Money;

/**
 * The preview table of the order editor (OE-10) as plain, already formatted arrays: «Позиция / было / станет» with the
 * stock impact of every row, the other changed fields, the totals with the difference and the warnings.
 */
final class EditPreviewPresenter
{
    private const MONEY_FIELDS = ['delivery_price', 'manual_discount'];

    /**
     * @return array{
     *     rows: list<array{name: string, was: string, will: string, stock: string|null, kind: string}>,
     *     fields: list<array{title: string, was: string, will: string}>,
     *     totals: list<array{title: string, was: string, will: string, bold: bool}>,
     *     delta: string,
     *     warnings: list<string>,
     *     changed: bool
     * }
     */
    public function present(EditPlan $plan, OrderType $type): array
    {
        $rows = [];

        foreach ($plan->removed as $item) {
            $effect = $plan->effectFor('r'.$item->id);
            $rows[] = [
                'name' => $item->displayName(),
                'was' => $this->line($item->qty, $item->price === null ? 0 : Money::fromRubles($item->price)),
                'will' => __('order_edit.preview.removed'),
                'stock' => $effect === null ? null : OrderEditor::stockNote($effect, $type, 0),
                'kind' => 'removed',
            ];
        }

        foreach ($plan->lines as $line) {
            $changed = $line->isNew() || $line->qty !== $line->oldQty || $line->priceChanged();
            $rows[] = [
                'name' => $line->label,
                'was' => $line->isNew() ? '—' : $this->line($line->oldQty, (int) $line->oldPrice),
                'will' => $this->line($line->qty, $line->price),
                'stock' => $line->effect === null ? null : OrderEditor::stockNote($line->effect, $type, $line->qty),
                'kind' => $line->isNew() ? 'added' : ($changed ? 'changed' : 'same'),
            ];
        }

        $fields = [];

        foreach ($plan->changes as $change) {
            if ($change->kind === OrderChangeKind::Stock || $change->itemId !== null || $change->label !== null || $change->field === null) {
                continue;
            }

            if (in_array($change->field, ['items_total', 'total', 'promo_discount'], true)) {
                continue;
            }

            $fields[] = [
                'title' => __('order_edit.fields.'.$change->field),
                'was' => $this->fieldValue($change->field, $change->old),
                'will' => $this->fieldValue($change->field, $change->new),
            ];
        }

        $totals = [
            ['title' => __('order_edit.fields.items_total'), 'was' => Money::format($plan->oldItemsTotal), 'will' => Money::format($plan->newItemsTotal), 'bold' => false],
        ];

        if ($plan->oldPromoDiscount > 0 || $plan->promoDiscount > 0) {
            $totals[] = ['title' => __('order_edit.fields.promo_discount'), 'was' => Money::format($plan->oldPromoDiscount), 'will' => Money::format($plan->promoDiscount), 'bold' => false];
        }

        $totals[] = ['title' => __('order_edit.fields.total'), 'was' => Money::format($plan->oldTotal), 'will' => Money::format($plan->newTotal), 'bold' => true];

        $delta = $plan->totalDelta();

        return [
            'rows' => $rows,
            'fields' => $fields,
            'totals' => $totals,
            'delta' => $delta === 0 ? '0' : ($delta > 0 ? '+' : '−').Money::format(abs($delta)),
            'warnings' => $plan->warnings,
            'changed' => $plan->hasChanges(),
        ];
    }

    private function line(int $qty, int $price): string
    {
        return $qty.' × '.Money::format($price).' = '.Money::format($qty * $price);
    }

    private function fieldValue(string $field, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return in_array($field, self::MONEY_FIELDS, true) ? Money::format(Money::fromRubles($value)) : $value;
    }
}
