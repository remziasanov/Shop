<?php

declare(strict_types=1);

namespace App\Filament\Resources\PriceRules;

use App\Filament\Resources\PriceRules\Pages\CreatePriceRule;
use App\Filament\Resources\PriceRules\Pages\EditPriceRule;
use App\Filament\Resources\PriceRules\Pages\ListPriceRules;
use App\Filament\Resources\PriceRules\Pages\ViewPriceRule;
use App\Filament\Resources\PriceRules\Schemas\PriceRuleForm;
use App\Filament\Resources\PriceRules\Tables\PriceRulesTable;
use App\Models\PriceRule;
use App\Services\Pricing\PriceRuleSaver;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Price rules (TZ 6.8, AD-03 group «Цены»). Administrators manage them, managers only view (TZ 6.13).
 */
class PriceRuleResource extends Resource
{
    protected static ?string $model = PriceRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'price-rules';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('shop.navigation.prices');
    }

    public static function getModelLabel(): string
    {
        return __('admin_pricing.rules.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin_pricing.rules.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return PriceRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PriceRulesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPriceRules::route('/'),
            'create' => CreatePriceRule::route('/create'),
            'view' => ViewPriceRule::route('/{record}'),
            'edit' => EditPriceRule::route('/{record}/edit'),
        ];
    }

    /**
     * Delete that recalculates the prices the rule used to affect.
     */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->using(function (PriceRule $record): bool {
                app(PriceRuleSaver::class)->delete($record);

                return true;
            });
    }
}
