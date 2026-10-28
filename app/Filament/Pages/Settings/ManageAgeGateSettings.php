<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Enums\AgeGateMode;
use App\Enums\AgeRestriction;
use App\Filament\Concerns\AdminOnlySettingsPage;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Products\ProductResource;
use App\Services\Age\AgeRestrictionResolver;
use App\Settings\AgeGateSettings;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Settings → «Возрастное ограничение» (AG-02, AG-14): the fields, a live preview of the overlay and the counters of
 * restricted products and categories.
 */
class ManageAgeGateSettings extends SettingsPage
{
    use AdminOnlySettingsPage;

    protected static string $settings = AgeGateSettings::class;

    protected static string $translationKey = 'age_gate';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 80;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('age_gate.settings.sections.behaviour'))
                ->description(__('age_gate.settings.good_faith'))
                ->schema([
                    Toggle::make('enabled')
                        ->label(__('age_gate.settings.fields.enabled')),
                    Select::make('mode')
                        ->label(__('age_gate.settings.fields.mode'))
                        ->helperText(__('age_gate.settings.hints.mode'))
                        ->options(AgeGateMode::options())
                        ->required()
                        ->native(false),
                    Select::make('site_min_age')
                        ->label(__('age_gate.settings.fields.site_min_age'))
                        ->helperText(__('age_gate.settings.hints.site_min_age'))
                        ->options([16 => AgeRestriction::Age16->label(), 18 => AgeRestriction::Age18->label()])
                        ->required()
                        ->native(false)
                        ->live(),
                    TextInput::make('remember_days')
                        ->label(__('age_gate.settings.fields.remember_days'))
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(365)
                        ->required(),
                    Toggle::make('show_badge')
                        ->label(__('age_gate.settings.fields.show_badge')),
                ])->columns(2),
            Section::make(__('age_gate.settings.sections.texts'))
                ->description(__('age_gate.settings.hints.placeholder'))
                ->schema([
                    TextInput::make('title')
                        ->label(__('age_gate.settings.fields.title'))
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true),
                    Textarea::make('text')
                        ->label(__('age_gate.settings.fields.text'))
                        ->required()
                        ->rows(3)
                        ->maxLength(1000)
                        ->live(onBlur: true),
                    TextInput::make('yes_label')
                        ->label(__('age_gate.settings.fields.yes_label'))
                        ->required()
                        ->maxLength(100)
                        ->live(onBlur: true),
                    TextInput::make('no_label')
                        ->label(__('age_gate.settings.fields.no_label'))
                        ->required()
                        ->maxLength(100)
                        ->live(onBlur: true),
                    Textarea::make('decline_message')
                        ->label(__('age_gate.settings.fields.decline_message'))
                        ->required()
                        ->rows(2)
                        ->maxLength(500),
                ])->columns(2),
            Section::make(__('age_gate.settings.sections.preview'))->schema([
                SchemaView::make('filament.age-gate.preview')
                    ->viewData(fn (Get $get): array => $this->previewData($get)),
            ]),
            Section::make(__('age_gate.settings.sections.counters'))->schema([
                SchemaView::make('filament.age-gate.counters')
                    ->viewData(fn (): array => $this->counterData()),
            ]),
        ])->columns(1);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['mode'] = AgeGateMode::from((string) $data['mode']);
        $data['site_min_age'] = AgeRestriction::fromAge((int) ($data['site_min_age'] ?? 18))?->minAge() ?? 18;
        $data['remember_days'] = max(1, min(365, (int) ($data['remember_days'] ?? 30)));

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function previewData(Get $get): array
    {
        $age = (string) ($get('site_min_age') ?: 18);
        $replace = fn (mixed $text): string => str_replace('{age}', $age, (string) $text);

        return [
            'age' => $age,
            'title' => $replace($get('title')),
            'text' => $replace($get('text')),
            'yes' => $replace($get('yes_label')),
            'no' => $replace($get('no_label')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function counterData(): array
    {
        $filtered = fn (string $value): string => ProductResource::getUrl('index', ['filters' => ['age_restriction' => ['value' => $value]]]);

        return [
            'counts' => app(AgeRestrictionResolver::class)->counts(),
            'productsUrl' => $filtered('any'),
            'categoriesUrl' => CategoryResource::getUrl('index'),
        ];
    }
}
