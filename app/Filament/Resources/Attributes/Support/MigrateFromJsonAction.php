<?php

declare(strict_types=1);

namespace App\Filament\Resources\Attributes\Support;

use App\Filament\Resources\Attributes\Pages\ListAttributes;
use App\Filament\Widgets\AttributeMigrationWidget;
use App\Jobs\MigrateAttributesFromJson;
use App\Models\Attribute;
use App\Models\User;
use App\Services\Catalog\AttributeMigrationState;
use App\Services\Catalog\JsonAttributeMigrator;
use App\Services\Users\PermissionService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * «Перенести характеристики из JSON» (FL-04): step 1 is the preview of the dry run in the modal, step 2 is the
 * confirmation that queues {@see MigrateAttributesFromJson}. The progress «N из M» and the banner are shown by
 * {@see AttributeMigrationWidget}. Only the dry run and the dispatch live here.
 */
class MigrateFromJsonAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'migrate_from_json';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('admin_attributes.migration.action'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (): bool => self::allowed() && ! app(AttributeMigrationState::class)->isMigrated())
            ->modalHeading(__('admin_attributes.migration.heading'))
            ->modalSubmitActionLabel(__('admin_attributes.migration.confirm'))
            ->modalWidth('3xl')
            ->mountUsing(function (ListAttributes $livewire): void {
                abort_unless(self::allowed(), 403);

                $livewire->migrationReport = app(JsonAttributeMigrator::class)->dryRun()->toArray();
            })
            ->modalContent(fn (ListAttributes $livewire) => view('filament.attributes.migration-preview', [
                'report' => $livewire->migrationReport ?? [],
                'existingAttributes' => Attribute::query()->count(),
                'progress' => app(AttributeMigrationState::class)->current(),
            ]))
            ->action(function (ListAttributes $livewire): void {
                abort_unless(self::allowed(), 403);

                $livewire->migrationReport = null;
                MigrateAttributesFromJson::dispatch();

                Notification::make()->success()->title(__('admin_attributes.migration.queued'))->send();
            });
    }

    private static function allowed(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(PermissionService::class)->allows($user, 'attributes.update');
    }
}
