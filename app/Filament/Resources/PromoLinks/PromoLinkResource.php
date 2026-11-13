<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoLinks;

use App\Filament\Resources\PromoLinks\Pages\CreatePromoLink;
use App\Filament\Resources\PromoLinks\Pages\EditPromoLink;
use App\Filament\Resources\PromoLinks\Pages\ListPromoLinks;
use App\Filament\Resources\PromoLinks\Pages\ViewPromoLink;
use App\Filament\Resources\PromoLinks\Schemas\PromoLinkForm;
use App\Filament\Resources\PromoLinks\Schemas\PromoLinkInfolist;
use App\Filament\Resources\PromoLinks\Tables\PromoLinksTable;
use App\Models\PromoLink;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Promo links `/go/{slug}` (TZ 22.4, PM-21…PM-27), group «Продвижение». Rights: `promo_links.view|create|update|delete`.
 */
class PromoLinkResource extends Resource
{
    protected static ?string $model = PromoLink::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'promo-links';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('promo_admin.navigation.group');
    }

    public static function getModelLabel(): string
    {
        return __('promo_admin.links.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('promo_admin.links.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return PromoLinkForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PromoLinkInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PromoLinksTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPromoLinks::route('/'),
            'create' => CreatePromoLink::route('/create'),
            'view' => ViewPromoLink::route('/{record}'),
            'edit' => EditPromoLink::route('/{record}/edit'),
        ];
    }
}
