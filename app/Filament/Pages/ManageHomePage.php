<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Exceptions\ImageProcessingException;
use App\Filament\Support\HomeBlocksSchema;
use App\Models\User;
use App\Services\Home\HomeHtmlBlocks;
use App\Services\Home\HomeImages;
use App\Services\Users\PermissionService;
use App\Settings\HomePageSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Home page builder (5.2, AD-20): add, reorder, collapse and hide blocks. Needs `home.update`;
 * the raw «HTML» block needs `html_block.edit` (6.13): without it the block is neither shown nor changed,
 * and it is kept in place on save.
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

        return $user instanceof User && app(PermissionService::class)->allows($user, 'home.update');
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
            HomeBlocksSchema::builder($this->canEditHtml()),
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

        // Without html_block.edit the raw HTML of such blocks is never sent to the browser.
        $data['blocks'] = app(HomeHtmlBlocks::class)->visibleTo($this->user() ?? abort(403), $blocks);

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

        $data['blocks'] = $this->savedBlocks = app(HomeHtmlBlocks::class)->prepareForSave($this->user() ?? abort(403), $blocks, $this->storedBlocks);

        return $data;
    }

    protected function afterSave(): void
    {
        app(HomeImages::class)->deleteObsolete($this->storedBlocks, $this->savedBlocks);

        // Show what was stored (processed images instead of the consumed uploads) so a second save is consistent.
        $this->form->fill($this->mutateFormDataBeforeFill(['blocks' => $this->savedBlocks]));
    }

    private function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    private function canEditHtml(): bool
    {
        $user = $this->user();

        return $user !== null && app(HomeHtmlBlocks::class)->canEdit($user);
    }
}
