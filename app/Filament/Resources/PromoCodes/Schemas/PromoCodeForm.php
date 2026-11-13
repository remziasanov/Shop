<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoCodes\Schemas;

use App\Models\PromoCode;
use App\Services\Promo\PromoCodeNormalizer;
use App\Services\Promo\PromoCodeSaver;
use Closure;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * Promo code form (PM-01…PM-06). The code text is normalized (upper case) and unique without regard to case; it is
 * locked once the code has been used in an order (PM-19).
 */
final class PromoCodeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('promo_admin.codes.sections.main'))
                ->schema([
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        TextInput::make('code')
                            ->label(__('promo_admin.codes.fields.code'))
                            ->helperText(fn (?Model $record): string => $record instanceof PromoCode && self::locked($record)
                                ? __('promo_admin.codes.fields.code_locked')
                                : __('promo_admin.codes.fields.code_hint'))
                            ->required()
                            ->maxLength(PromoCodeNormalizer::MAX_LENGTH)
                            ->disabled(fn (?Model $record): bool => $record instanceof PromoCode && self::locked($record))
                            ->dehydrated()
                            ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                            ->rules([fn (?Model $record): Closure => self::codeRule($record instanceof PromoCode ? $record : null)]),
                        TextInput::make('name')
                            ->label(__('promo_admin.codes.fields.name'))
                            ->required()
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->label(__('promo_admin.codes.fields.is_active'))
                            ->default(true),
                    ]),
                ]),
            ...PromoConditionFields::sections(),
            Section::make(__('promo_admin.codes.fields.confirm_discount_change'))
                ->description(__('promo_admin.codes.fields.confirm_discount_change_hint'))
                ->visible(fn (?Model $record): bool => $record instanceof PromoCode && $record->redemptions()->exists())
                ->hiddenOn('view')
                ->schema([
                    Checkbox::make('confirm_discount_change')
                        ->label(__('promo_admin.codes.fields.confirm_discount_change'))
                        ->dehydrated(false),
                ]),
        ]);
    }

    /**
     * PM-19: the text of a code that has redemptions does not change.
     */
    public static function locked(PromoCode $code): bool
    {
        return $code->exists && $code->redemptions()->exists();
    }

    /**
     * PM-01: 4–40 Latin letters, digits and hyphens; unique ignoring case (the stored text is upper case).
     */
    private static function codeRule(?PromoCode $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            if ($record !== null && self::locked($record)) {
                return;
            }

            $text = PromoCodeNormalizer::normalize((string) $value);

            if (! PromoCodeNormalizer::isValid($text)) {
                $fail(__('promo_admin.codes.validation.code_format'));

                return;
            }

            if (app(PromoCodeSaver::class)->codeTaken($text, $record)) {
                $fail(__('promo_admin.codes.validation.code_unique'));
            }
        };
    }
}
