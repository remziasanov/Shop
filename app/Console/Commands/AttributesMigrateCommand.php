<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Attribute;
use App\Services\Catalog\AttributeTypeInference;
use App\Services\Catalog\JsonAttributeMigrator;
use App\Services\Catalog\MigrationReport;
use Illuminate\Console\Command;

/**
 * Fallback of the admin action «Перенести характеристики из JSON» (FL-04): `--dry-run` prints the report, without it
 * the migration runs in this process, batch by batch, and continues from the stored position when restarted.
 */
class AttributesMigrateCommand extends Command
{
    protected $signature = 'shop:attributes-migrate {--dry-run : Only report what would be created}';

    protected $description = 'Migrate product characteristics from the JSON column to the normalized tables (FL-04)';

    public function handle(JsonAttributeMigrator $migrator): int
    {
        if ($this->option('dry-run')) {
            $this->report($migrator->dryRun());

            return self::SUCCESS;
        }

        $skipped = 0;

        $migrator->run(null, function (array $progress) use (&$skipped): void {
            $skipped = $progress['skipped'];
            $this->line(__('attributes.migration.command.progress', ['done' => $progress['done'], 'total' => $progress['total']]));
        });

        $this->info(__('attributes.migration.command.finished', ['attributes' => Attribute::query()->count(), 'skipped' => $skipped]));

        return self::SUCCESS;
    }

    private function report(MigrationReport $report): void
    {
        $this->info(__('attributes.migration.command.dry_run_title'));
        $this->line(__('attributes.migration.command.products', ['count' => $report->products]));
        $this->line(__('attributes.migration.command.to_create', ['count' => count($report->toCreate())]));
        $this->line(__('attributes.migration.command.existing', ['count' => count($report->existing())]));
        $this->line(__('attributes.migration.command.values', ['count' => $report->valuesToCreate()]));

        foreach ($report->mergedNames() as $names) {
            $this->line(__('attributes.migration.command.merged', ['names' => implode(' = ', $names)]));
        }

        foreach ($report->disputed() as $planned) {
            $this->warn(__('attributes.migration.command.disputed', [
                'name' => $planned->name,
                'type' => $planned->inference->type->label(),
                'share' => round($planned->inference->numericShare * 100),
            ]));
        }

        foreach ($report->nonFilterable() as $planned) {
            $this->warn(__('attributes.migration.command.non_filterable', [
                'name' => $planned->name,
                'reason' => $planned->inference->nonFilterableReason === AttributeTypeInference::REASON_LONG
                    ? __('attributes.migration.command.reason_long', ['max' => 100])
                    : __('attributes.migration.command.reason_distinct', ['max' => 200]),
            ]));
        }
    }
}
