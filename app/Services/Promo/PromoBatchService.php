<?php

declare(strict_types=1);

namespace App\Services\Promo;

use App\Enums\ExportType;
use App\Enums\PromoBatchStatus;
use App\Enums\PromoScope;
use App\Enums\PromoTargetType;
use App\Enums\PromoType;
use App\Jobs\GeneratePromoCodeBatch;
use App\Models\Export;
use App\Models\PromoCode;
use App\Models\PromoCodeBatch;
use App\Models\User;
use App\Services\Export\ExportManager;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Bulk generation of one-time codes (PM-15): creates the batch with its template and starts the job
 * ({@see GeneratePromoCodeBatch}), changes the template of a batch and carries it over to the unused codes, asks for the
 * export of a batch (PM-16).
 *
 * Template keys (all optional except `type`): `type` (PromoType), `value` (percent or rubles string), `scope`
 * (PromoScope), `include_subcategories`, `min_order_amount` (rubles string), `starts_at`, `ends_at` (Y-m-d H:i:s),
 * `only_registered`, `applies_to_discounted`, `stackable_with_promotions`, `is_active`, `group_ids` (list of group ids;
 * empty means «Гость» and «Розница»), `targets` (list of `{type: category|product|brand, id?: int, value?: string}`).
 * The usage limits are not part of the template: every generated code has limit 1 per code and per customer.
 *
 * @phpstan-type Template array{type?: string, value?: string|null, scope?: string, include_subcategories?: bool, min_order_amount?: string|null, starts_at?: string|null, ends_at?: string|null, only_registered?: bool, applies_to_discounted?: bool, stackable_with_promotions?: bool, is_active?: bool, group_ids?: list<int>, targets?: list<array{type: string, id?: int|null, value?: string|null}>}
 */
final class PromoBatchService
{
    public const MAX_COUNT = 5000;

    public const MIN_LENGTH = 6;

    public const MAX_LENGTH = 12;

    public const MAX_PREFIX = 20;

    /**
     * @param  Template  $template
     *
     * @throws InvalidArgumentException a parameter is out of range (Russian message)
     */
    public function create(string $name, string $prefix, int $count, int $codeLength, array $template, ?User $user = null): PromoCodeBatch
    {
        $prefix = PromoCodeNormalizer::normalize($prefix);

        if ($count < 1 || $count > self::MAX_COUNT) {
            throw new InvalidArgumentException(__('promo.errors.count_range', ['max' => self::MAX_COUNT]));
        }

        if ($codeLength < self::MIN_LENGTH || $codeLength > self::MAX_LENGTH) {
            throw new InvalidArgumentException(__('promo.errors.length_range', ['min' => self::MIN_LENGTH, 'max' => self::MAX_LENGTH]));
        }

        if ($prefix !== '' && (strlen($prefix) > self::MAX_PREFIX || preg_match('/^[A-Z0-9-]+$/', $prefix) !== 1)) {
            throw new InvalidArgumentException(__('promo.errors.prefix_invalid'));
        }

        $batch = PromoCodeBatch::query()->create([
            'name' => $name,
            'prefix' => $prefix,
            'count' => $count,
            'code_length' => $codeLength,
            'template' => $this->normalizeTemplate($template),
            'generated' => 0,
            'status' => PromoBatchStatus::Queued,
            'created_by' => $user?->id,
        ]);

        GeneratePromoCodeBatch::dispatch($batch->id);

        return $batch;
    }

    /**
     * PM-15: stores a new template (when given) and applies the template of the batch to all its UNUSED codes (no use
     * counted, no redemption, re-checked inside the UPDATE); used codes keep the conditions they were used under.
     * `is_active` is not carried over, so individually deactivated codes stay as they are. Returns the number of
     * updated codes.
     *
     * @param  Template|null  $template
     */
    public function applyTemplate(PromoCodeBatch $batch, ?array $template = null): int
    {
        if ($template !== null) {
            $batch->template = $this->normalizeTemplate($template);
            $batch->save();
        }

        $template = $this->normalizeTemplate($batch->template ?? []);
        $updated = 0;

        PromoCode::query()
            ->where('batch_id', $batch->id)
            ->where('usage_count', 0)
            ->orderBy('id')
            ->select('id')
            ->chunkById(500, function ($codes) use ($template, &$updated): void {
                $ids = $codes->pluck('id')->map(fn ($id): int => (int) $id)->all();

                $columns = $this->columns($template);
                unset($columns['is_active']);

                DB::transaction(function () use ($ids, $columns, $template, &$updated): void {
                    // Unused is re-checked here: a code redeemed since the selection must not be changed.
                    $ids = DB::table('promo_codes')
                        ->whereIn('id', $ids)
                        ->where('usage_count', 0)
                        ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('promo_redemptions')->whereColumn('promo_redemptions.promo_code_id', 'promo_codes.id'))
                        ->lockForUpdate()
                        ->pluck('id')
                        ->map(fn ($id): int => (int) $id)
                        ->all();

                    if ($ids === []) {
                        return;
                    }

                    DB::table('promo_codes')->whereIn('id', $ids)->where('usage_count', 0)->update($columns + ['updated_at' => now()]);
                    $this->replaceRelations($ids, $template);
                    $updated += count($ids);
                });
            });

        return $updated;
    }

    /**
     * The model columns of the template (without the per-code limits).
     *
     * @param  Template  $template
     * @return array<string, mixed>
     */
    public function columns(array $template): array
    {
        $type = PromoType::from($template['type'] ?? PromoType::Percent->value);

        return [
            'type' => $type->value,
            'value' => $type->hasValue() ? ($template['value'] ?? null) : null,
            'scope' => $template['scope'] ?? PromoScope::All->value,
            'include_subcategories' => (bool) ($template['include_subcategories'] ?? true),
            'min_order_amount' => $template['min_order_amount'] ?? null,
            'starts_at' => $template['starts_at'] ?? null,
            'ends_at' => $template['ends_at'] ?? null,
            'only_registered' => (bool) ($template['only_registered'] ?? false),
            'applies_to_discounted' => (bool) ($template['applies_to_discounted'] ?? false),
            'stackable_with_promotions' => (bool) ($template['stackable_with_promotions'] ?? false),
            'is_active' => (bool) ($template['is_active'] ?? true),
        ];
    }

    /**
     * Replaces the groups and targets of the codes with those of the template.
     *
     * @param  list<int>  $codeIds
     * @param  Template  $template
     */
    public function replaceRelations(array $codeIds, array $template): void
    {
        if ($codeIds === []) {
            return;
        }

        DB::table('promo_code_groups')->whereIn('promo_code_id', $codeIds)->delete();
        DB::table('promo_code_targets')->whereIn('promo_code_id', $codeIds)->delete();

        $groups = $template['group_ids'] ?? [];
        $groups = $groups === [] ? PromoCode::defaultGroupIds() : array_values(array_unique(array_map('intval', $groups)));
        $groupRows = [];
        $targetRows = [];

        foreach ($codeIds as $id) {
            foreach ($groups as $groupId) {
                $groupRows[] = ['promo_code_id' => $id, 'customer_group_id' => $groupId];
            }

            foreach ($template['targets'] ?? [] as $target) {
                $type = PromoTargetType::from($target['type']);
                $targetRows[] = [
                    'promo_code_id' => $id,
                    'target_type' => $type->value,
                    'target_id' => $type === PromoTargetType::Brand ? null : ($target['id'] ?? null),
                    'target_value' => $type === PromoTargetType::Brand ? ($target['value'] ?? null) : null,
                ];
            }
        }

        foreach (array_chunk($groupRows, 500) as $chunk) {
            DB::table('promo_code_groups')->insert($chunk);
        }

        foreach (array_chunk($targetRows, 500) as $chunk) {
            DB::table('promo_code_targets')->insert($chunk);
        }
    }

    /**
     * PM-16: the export of the codes of a batch through the exports journal (kept 7 days, EX-03).
     */
    public function requestExport(PromoCodeBatch $batch, ?User $user): Export
    {
        return app(ExportManager::class)->request(ExportType::PromoBatch, ['batch_id' => $batch->id], $user);
    }

    /**
     * @param  array<string, mixed>  $template
     * @return Template
     */
    private function normalizeTemplate(array $template): array
    {
        $template['type'] = PromoType::from((string) ($template['type'] ?? PromoType::Percent->value))->value;
        $template['scope'] = PromoScope::from((string) ($template['scope'] ?? PromoScope::All->value))->value;

        /** @var Template $template */
        return $template;
    }
}
