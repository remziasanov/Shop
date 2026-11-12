<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\AttributeType;
use App\Exceptions\AttributeException;
use App\Models\Attribute;
use App\Models\AttributeValue;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Migration of the v1 characteristics (`products.attributes` JSON) into the normalized tables (FL-04, D-076).
 *
 *  - {@see dryRun()}: scans every product, reports what would be created (nothing is written);
 *  - {@see prepare()}: creates the missing characteristics (new ones are filterable unless FL-04 forbids it);
 *  - {@see migrateBatch()}: writes the values of up to 500 products after a given id;
 *  - {@see run()}: prepare + batches with progress, optionally within a time budget (queue job: ≤ 40 s per run).
 *
 * Names are matched by the rules of FL-16 ({@see AttributeNameResolver}); types come from {@see AttributeStats}.
 * Idempotent: existing characteristics are reused, a product that already has rows in the tables is skipped (the
 * tables are the source of truth from its first write on), so a repeated or resumed run changes nothing twice.
 * Values that do not fit the type of their characteristic (a word in a number) are dropped and counted (D-112): the
 * dry run lists them, the original JSON of every product is appended to a backup file before the product is written
 * ({@see AttributeJsonBackup}) and `shop:attributes-restore-json` brings it back. The JSON of a migrated product is
 * always rebuilt from its rows, also when all its values were dropped.
 */
class JsonAttributeMigrator
{
    public const BATCH = 500;

    /** @var array<string, Attribute|null> raw JSON name => attribute, per run */
    private array $attributeByName = [];

    public function __construct(
        private readonly AttributeService $attributes,
        private readonly AttributeMigrationState $state,
        private readonly AttributeSyncService $sync,
        private readonly AttributeJsonBackup $backup,
    ) {}

    private ?string $fallbackRunId = null;

    /**
     * Холостой прогон: nothing is written.
     */
    public function dryRun(): MigrationReport
    {
        [$plan, $products] = $this->buildPlan();

        return new MigrationReport($products, $plan, ...$this->scanLoss($plan));
    }

    /**
     * Creates the characteristics of the plan that are missing in the dictionary.
     *
     * @return list<Attribute> the created attributes
     */
    public function prepare(): array
    {
        [$plan] = $this->buildPlan();
        $created = [];

        foreach ($plan as $planned) {
            if ($planned->isNew()) {
                $created[] = $this->attributes->createFromResolved($planned->resolved, $planned->inference, true);
            }
        }

        $this->attributeByName = [];
        CatalogVersion::bump();

        return $created;
    }

    /**
     * Writes the values of the next products after `$afterId` (ordered by id, at most `$limit`).
     */
    public function migrateBatch(int $afterId, int $limit = self::BATCH): MigrationBatchResult
    {
        $rows = DB::table('products')
            ->whereNotNull('attributes')
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'attributes']);

        $ids = $rows->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $already = $ids === [] ? [] : array_flip(DB::table('product_attribute_values')->whereIn('product_id', $ids)->distinct()->pluck('product_id')->map(fn ($id): int => (int) $id)->all());

        $products = $migrated = $alreadyMigrated = $skipped = 0;
        /** @var array<int, list<array{attribute: Attribute, values: list<string>}>> $groups */
        $groups = [];
        /** @var array<int, mixed> $originals */
        $originals = [];

        foreach ($rows as $row) {
            $specs = $this->specsOf($row->attributes);

            if ($specs === []) {
                continue;
            }

            $products++;

            if (isset($already[(int) $row->id])) {
                $alreadyMigrated++;

                continue;
            }

            $lost = [];
            $groups[(int) $row->id] = $this->groupsOf($specs, fn (string $name): ?Attribute => $this->attributeFor($name), $lost);
            $originals[(int) $row->id] = json_decode((string) $row->attributes, true);
            $skipped += count($lost);
        }

        // The original JSON goes to the backup before anything of the batch is overwritten.
        $this->backup->append($this->runId(), $originals);

        $emptied = [];

        foreach ($groups as $productId => $entries) {
            if ($entries === []) {
                // Every value was dropped: the JSON must not stay as a stale copy that a later sync wipes silently.
                $emptied[] = $productId;

                continue;
            }

            $items = array_map(fn (array $entry): array => ['attribute_id' => $entry['attribute']->id, 'values' => $entry['values']], $entries);
            $this->attributes->setProductValues($productId, $items, true, false);
            $migrated++;
        }

        if ($emptied !== []) {
            $this->sync->syncProducts($emptied, true);
        }

        return new MigrationBatchResult(
            lastId: $ids === [] ? $afterId : max($ids),
            products: $products,
            migrated: $migrated,
            alreadyMigrated: $alreadyMigrated,
            skippedValues: $skipped,
            done: count($ids) < $limit,
        );
    }

    /**
     * Runs the whole migration: the attributes first, then batches from the stored position until the end or until
     * `$budgetSeconds` have passed (checked before each batch). With a budget the first run only prepares the attributes
     * and counts the products. Returns true when the migration is finished (the flag
     * `attributes_migrated_at` is set), false when it has to be continued by another run.
     *
     * @param  int|null  $budgetSeconds  null = no limit (console)
     * @param  Closure(array{total: int, done: int, skipped: int, created: int}): void|null  $onProgress  after each batch
     */
    public function run(?int $budgetSeconds = null, ?Closure $onProgress = null): bool
    {
        $started = microtime(true);

        if ($this->state->current() === null) {
            $created = $this->prepare();
            $this->state->begin($this->state->jsonProductCount(), count($created));

            // Planning scans every product: with a time budget (queue job) it is a run of its own, so that no run
            // adds a batch on top of it and exceeds the 40 s job limit.
            if ($budgetSeconds !== null) {
                return false;
            }
        }

        do {
            $state = $this->state->current();

            if ($state === null) {
                break;
            }

            $batch = $this->migrateBatch($state['last_id']);
            $this->state->advance($batch->lastId, $batch->products, $batch->skippedValues);
            CatalogVersion::bump();

            if ($onProgress !== null && ($progress = $this->state->current()) !== null) {
                $onProgress($progress);
            }

            if ($batch->done) {
                $this->state->finish();
                CatalogVersion::bump();

                return true;
            }
        } while ($budgetSeconds === null || microtime(true) - $started < $budgetSeconds);

        return false;
    }

    /**
     * Id of the run for the backup file name: the one of the stored progress, or one per instance (direct batches).
     */
    private function runId(): string
    {
        return $this->state->current()['run_id'] ?? ($this->fallbackRunId ??= now()->format('Ymd-His'));
    }

    // ------------------------------------------------------------------
    // Planning
    // ------------------------------------------------------------------

    /**
     * Dry run of the loss: values that the real run would drop (do not fit the type, a second value of a single-value
     * characteristic, too long). Products that already have rows are not touched by the migration and not counted.
     *
     * @param  list<PlannedAttribute>  $plan
     * @return array{lostValues: int, lostProducts: int, lostExamples: list<array{product_id: int, characteristic: string, value: string}>}
     */
    private function scanLoss(array $plan): array
    {
        /** @var array<string, Attribute> $targets normalized JSON name => existing or transient attribute */
        $targets = [];
        /** @var array<int, Attribute> $transient plan index => transient attribute of a new characteristic */
        $transient = [];

        foreach ($plan as $index => $planned) {
            $owner = $planned->mergedInto ?? $index;
            $attribute = $planned->existing ?? ($transient[$owner] ??= (new Attribute)->forceFill(['name' => $plan[$owner]->name, 'type' => $plan[$owner]->inference->type, 'unit' => $plan[$owner]->unit]));

            foreach ($planned->names as $name) {
                $targets[AttributeNameResolver::normalize($name)] = $attribute;
            }
        }

        $values = $products = 0;
        $examples = [];

        DB::table('products')->whereNotNull('attributes')->select(['id', 'attributes'])->chunkById(self::BATCH, function ($rows) use ($targets, &$values, &$products, &$examples): void {
            $ids = $rows->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $already = array_flip(DB::table('product_attribute_values')->whereIn('product_id', $ids)->distinct()->pluck('product_id')->map(fn ($id): int => (int) $id)->all());

            foreach ($rows as $row) {
                $specs = $this->specsOf($row->attributes);

                if ($specs === [] || isset($already[(int) $row->id])) {
                    continue;
                }

                $lost = [];
                $this->groupsOf($specs, fn (string $name): ?Attribute => $targets[AttributeNameResolver::normalize($name)] ?? null, $lost);

                if ($lost === []) {
                    continue;
                }

                $products++;
                $values += count($lost);

                foreach ($lost as $item) {
                    if (count($examples) < MigrationReport::EXAMPLES) {
                        $examples[] = ['product_id' => (int) $row->id, 'characteristic' => $item['name'], 'value' => $item['value']];
                    }
                }
            }
        });

        return ['lostValues' => $values, 'lostProducts' => $products, 'lostExamples' => $examples];
    }

    /**
     * @return array{0: list<PlannedAttribute>, 1: int} the plan and the number of products with JSON characteristics
     */
    private function buildPlan(): array
    {
        /** @var array<string, array{names: array<string, int>, stats: AttributeStats, products: int}> $groups */
        $groups = [];
        $products = 0;

        DB::table('products')->whereNotNull('attributes')->select(['id', 'attributes'])->chunkById(self::BATCH, function ($rows) use (&$groups, &$products): void {
            foreach ($rows as $row) {
                $specs = $this->specsOf($row->attributes);

                if ($specs === []) {
                    continue;
                }

                $products++;

                foreach ($specs as $spec) {
                    $key = AttributeNameResolver::normalize($spec['name']);
                    $groups[$key] ??= ['names' => [], 'stats' => new AttributeStats, 'products' => 0];
                    $groups[$key]['names'][$spec['name']] = ($groups[$key]['names'][$spec['name']] ?? 0) + 1;
                    $groups[$key]['stats']->add($spec['value']);
                    $groups[$key]['products']++;
                }
            }
        });

        $resolver = $this->attributes->resolver();
        $plan = [];
        /** @var array<string, int> $planned normalized signature => plan index of the new attributes */
        $planned = [];
        /** @var array<int, array<string, string>> $dictionary existing attribute id => normalized value => text */
        $dictionary = [];

        foreach ($groups as $group) {
            arsort($group['names']);
            $names = array_keys($group['names']);
            $resolved = $resolver->resolve($names[0]);
            $stats = $group['stats'];

            if ($resolved->attribute !== null) {
                $attribute = $resolved->attribute;
                $inference = $stats->infer($attribute->unit);
                $newValues = 0;

                if ($attribute->type === AttributeType::List) {
                    $dictionary[$attribute->id] ??= AttributeValue::query()->where('attribute_id', $attribute->id)->pluck('value')->mapWithKeys(fn ($v): array => [AttributeNameResolver::normalize((string) $v) => (string) $v])->all();
                    $newValues = count(array_diff_key($stats->distinctValues(), $dictionary[$attribute->id]));
                }

                $plan[] = new PlannedAttribute($names, $attribute, $resolved, $inference, $attribute->name, $attribute->unit, $newValues, $group['products']);

                continue;
            }

            $inference = $stats->infer($resolved->unit);
            $number = $inference->type === AttributeType::Number;
            $name = $number ? $resolved->name : $resolved->header;
            $unit = $number ? ($resolved->unit ?? $inference->unit) : null;
            $signature = AttributeNameResolver::normalize($unit !== null ? $name.', '.$unit : $name);
            $mergedInto = $planned[$signature] ?? null;
            $newValues = $inference->type === AttributeType::List ? count($stats->distinctValues()) : 0;

            if ($mergedInto === null) {
                $planned[$signature] = count($plan);
            }

            $plan[] = new PlannedAttribute($names, null, $resolved, $inference, $name, $unit, $newValues, $group['products'], $mergedInto);
        }

        return [$plan, $products];
    }

    // ------------------------------------------------------------------
    // Batch helpers
    // ------------------------------------------------------------------

    /**
     * @return list<array{name: string, value: string}> rows with a name and a value
     */
    private function specsOf(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : $json;
        $specs = [];

        if (! is_array($decoded)) {
            return $specs;
        }

        foreach ($decoded as $row) {
            if (! is_array($row) || ! isset($row['name']) || ! is_scalar($row['name'])) {
                continue;
            }

            $name = AttributeValueParser::collapse((string) $row['name']);
            $value = isset($row['value']) && is_scalar($row['value']) ? AttributeValueParser::collapse((string) $row['value']) : '';

            if ($name !== '' && $value !== '') {
                $specs[] = ['name' => $name, 'value' => $value];
            }
        }

        return $specs;
    }

    /**
     * Values grouped by the resolved attribute, in the order of first appearance; values that do not fit the type of
     * their attribute (or have none) are not in the result and are added to `$lost`.
     *
     * @param  list<array{name: string, value: string}>  $specs
     * @param  Closure(string): ?Attribute  $resolve  attribute of a JSON name
     * @param  list<array{name: string, value: string}>  $lost
     * @return list<array{attribute: Attribute, values: list<string>}>
     */
    private function groupsOf(array $specs, Closure $resolve, array &$lost): array
    {
        /** @var array<string, array{attribute: Attribute, values: list<string>}> $byAttribute */
        $byAttribute = [];

        foreach ($specs as $spec) {
            $attribute = $resolve($spec['name']);

            if ($attribute === null) {
                $lost[] = $spec;

                continue;
            }

            $key = $attribute->exists ? (string) $attribute->id : 'transient-'.spl_object_id($attribute);
            $values = $attribute->type === AttributeType::List ? AttributeValueParser::splitList($spec['value']) : [$spec['value']];
            $byAttribute[$key] ??= ['attribute' => $attribute, 'values' => []];

            foreach ($values as $value) {
                // Number and boolean take one value per product: the first one wins.
                if (($attribute->type !== AttributeType::List && $byAttribute[$key]['values'] !== []) || ! $this->fits($attribute, $value)) {
                    $lost[] = ['name' => $spec['name'], 'value' => $value];

                    continue;
                }

                $byAttribute[$key]['values'][] = $value;
            }
        }

        return array_values(array_filter($byAttribute, fn (array $entry): bool => $entry['values'] !== []));
    }

    private function fits(Attribute $attribute, string $value): bool
    {
        if ($attribute->type === AttributeType::List) {
            return mb_strlen($value) <= 255;
        }

        try {
            $this->attributes->rowsFor($attribute, $value);
        } catch (AttributeException) {
            return false;
        }

        return true;
    }

    private function attributeFor(string $name): ?Attribute
    {
        if (! array_key_exists($name, $this->attributeByName)) {
            $this->attributeByName[$name] = $this->attributes->resolver()->resolve($name)->attribute;
        }

        return $this->attributeByName[$name];
    }
}
