<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Exceptions\ImportRowException;
use App\Models\CustomerGroup;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Pricing\PriceTierService;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * Applies the group price cells of an import row to a product or a variant (GP-15). A cell sets the exact price (`qty` 1)
 * or the tier of that quantity, «-» deletes it, a blank cell keeps it (or deletes it under the «clear» empty-cell policy).
 * The merged set of the group is validated and written by {@see PriceTierService::replace()} without a recalculation: the
 * touched products get one at the end of the import ({@see ImportRunner::finish()}). Columns of unknown groups never get here
 * (the mapping drops them), a group deleted meanwhile is ignored.
 */
final class GroupPriceImporter
{
    /** @var array<string, CustomerGroup|null> lower-cased code => group */
    private array $groups = [];

    public function __construct(private readonly PriceTierService $tiers) {}

    /**
     * @param  list<array<string, mixed>>  $cells  `values['group_prices']` of the staged row
     * @return array{changed: bool, warnings: list<string>}
     *
     * @throws ImportRowException when a set is invalid (GP-14 errors) or a card with variants gets prices
     */
    public function apply(Product|ProductVariant $item, array $cells, bool $clear): array
    {
        $byGroup = [];

        foreach ($cells as $cell) {
            $byGroup[(string) ($cell['code'] ?? '')][] = $cell;
        }

        $changed = false;
        $warnings = [];

        foreach ($byGroup as $code => $groupCells) {
            $group = $this->group($code);

            if ($group === null) {
                continue;
            }

            $current = [];

            foreach ($this->tiers->rows($item, $group) as $row) {
                $current[$row['min_qty']] = $row['price'];
            }

            $wanted = $current;
            $sets = false;

            foreach ($groupCells as $cell) {
                $qty = (int) ($cell['qty'] ?? 1);

                switch ($cell['op'] ?? 'keep') {
                    case 'set':
                        $wanted[$qty] = Money::toRubles((int) $cell['price']);
                        $sets = true;

                        break;
                    case 'delete':
                        unset($wanted[$qty]);

                        break;
                    default:
                        if ($clear) {
                            unset($wanted[$qty]);
                        }
                }
            }

            if ($wanted === $current) {
                continue;
            }

            if ($sets && $item instanceof Product && $item->has_variants) {
                throw new ImportRowException(__('admin_group_prices.import.card_has_variants'));
            }

            $rows = [];

            foreach ($wanted as $qty => $price) {
                $rows[] = ['min_qty' => $qty, 'price' => $price];
            }

            try {
                $result = $this->tiers->replace($item, $group, $rows, recalculate: false);
            } catch (ValidationException $e) {
                throw new ImportRowException(__('admin_group_prices.import.not_updated', ['message' => $group->name.': '.(string) collect($e->errors())->flatten()->first()]));
            }

            $changed = $changed || $result->changed();

            foreach ($result->warnings as $issue) {
                $warnings[] = $group->name.': '.$issue->message;
            }
        }

        return ['changed' => $changed, 'warnings' => $warnings];
    }

    private function group(string $code): ?CustomerGroup
    {
        $key = mb_strtolower($code, 'UTF-8');

        if (! array_key_exists($key, $this->groups)) {
            $this->groups[$key] = CustomerGroup::query()->where('code', $key)->first();
        }

        return $this->groups[$key];
    }
}
