<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Models\Page;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * AD-60: static pages. Administrators and managers (TZ 6.13). Content is sanitized by the Page observer.
 */
class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'title';

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('shop.navigation.content');
    }

    public static function getModelLabel(): string
    {
        return __('admin_content.pages.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin_content.pages.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin_content.pages.sections.main'))->schema([
                TextInput::make('title')
                    ->label(__('admin_content.pages.fields.title'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('slug')
                    ->label(__('admin_content.pages.fields.slug'))
                    ->helperText(__('admin_content.pages.fields.slug_hint'))
                    ->maxLength(240),
                Toggle::make('is_active')
                    ->label(__('admin_content.pages.fields.is_active'))
                    ->default(true),
                RichEditor::make('content')
                    ->label(__('admin_content.pages.fields.content'))
                    ->columnSpanFull(),
            ])->columns(2),
            Section::make(__('admin_content.pages.sections.seo'))->schema([
                TextInput::make('meta_title')
                    ->label(__('admin_content.pages.fields.meta_title'))
                    ->maxLength(255),
                Textarea::make('meta_description')
                    ->label(__('admin_content.pages.fields.meta_description'))
                    ->rows(3)
                    ->maxLength(255),
            ])->columns(1)->collapsible(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('title')
            ->columns([
                TextColumn::make('title')
                    ->label(__('admin_content.pages.table.title'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label(__('admin_content.pages.table.slug'))
                    ->formatStateUsing(fn (string $state): string => '/page/'.$state),
                ToggleColumn::make('is_active')
                    ->label(__('admin_content.pages.table.is_active'))
                    ->disabled(fn (Page $record): bool => ! Gate::allows('update', $record)),
                TextColumn::make('updated_at')
                    ->label(__('admin_content.pages.table.updated_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                self::openOnSiteAction(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ])
            ->recordUrl(fn (Page $record): string => static::getUrl('edit', ['record' => $record]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPages::route('/'),
            'create' => CreatePage::route('/create'),
            'edit' => EditPage::route('/{record}/edit'),
        ];
    }

    /**
     * AD-05: the page on the storefront in a new tab; an inactive page is 404 there, so the link is offered for active pages only.
     */
    public static function openOnSiteAction(): Action
    {
        return Action::make('open_on_site')
            ->label(__('admin_content.pages.actions.open_on_site'))
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->color('gray')
            ->visible(fn (Page $record): bool => $record->is_active)
            ->url(fn (Page $record): string => route('page.show', $record->slug))
            ->openUrlInNewTab();
    }
}
