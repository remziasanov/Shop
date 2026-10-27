<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Exceptions\ImageProcessingException;
use App\Filament\Support\HomeBlocksSchema;
use App\Models\User;
use App\Services\Home\HomeHtmlBlocks;
use App\Services\Home\HomeImages;
use App\Settings\HomePageSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Home page builder (5.2, AD-20): add, reorder, collapse and hide blocks. Admin and manager can edit it;
 * the raw «HTML» block is administrator-only (6.13): managers neither see nor change it, and it is kept
 * in place when they save.
 */
class ManageHomePage extends SettingsPage
{
    protected static string $settings = HomePageSettings::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?int $navigationSort = 10;

    /** @var list<array<string, mixed>> Blocks stored before this save (for HTML merge and image cleanup). */
    protected array $storedBlocks = [];

    /** @var list<array<string, mixed>> Blocks being saved. */
    protected array $savedBlocks = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_active;
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('shop.navigation.content');
    }

    public static function getNavigationLabel(): string
    {
        return __('home.admin.title');
    }

    public function getTitle(): string
    {
        return __('home.admin.title');
    }

    public function getSavedNotificationTitle(): ?string
    {
        return __('shop.settings.saved');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            HomeBlocksSchema::builder($this->isAdmin()),
        ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label(__('home.admin.preview'))
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->url(route('home'), shouldOpenInNewTab: true),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $blocks = array_values((array) ($data['blocks'] ?? []));

        // Managers never receive the raw HTML of administrator blocks.
        $data['blocks'] = $this->isAdmin() ? $blocks : app(HomeHtmlBlocks::class)->without($blocks);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->storedBlocks = array_values((array) app(HomePageSettings::class)->blocks);
        $blocks = array_values((array) ($data['blocks'] ?? []));

        try {
            $blocks = app(HomeImages::class)->apply($blocks, $this->storedBlocks);
        } catch (ImageProcessingException $e) {
            throw ValidationException::withMessages(['data.blocks' => $e->getMessage()]);
        }

        $html = app(HomeHtmlBlocks::class);
        $data['blocks'] = $this->savedBlocks = $this->isAdmin() ? $blocks : $html->restore($blocks, $this->storedBlocks);

        return $data;
    }

    protected function afterSave(): void
    {
        app(HomeImages::class)->deleteObsolete($this->storedBlocks, $this->savedBlocks);

        // Show what was stored (processed images instead of the consumed uploads) so a second save is consistent.
        $this->form->fill($this->mutateFormDataBeforeFill(['blocks' => $this->savedBlocks]));
    }

    private function isAdmin(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isAdmin();
    }
}
