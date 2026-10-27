<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\AdminOnlySettingsPage;
use App\Settings\SeoSettings;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ManageSeoSettings extends SettingsPage
{
    use AdminOnlySettingsPage;

    protected static string $settings = SeoSettings::class;

    protected static string $translationKey = 'seo';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static ?int $navigationSort = 60;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('shop.settings.seo.sections.templates'))
                ->description(__('shop.settings.seo.hints.placeholders'))
                ->schema([
                    TextInput::make('product_title_template')
                        ->label(__('shop.settings.seo.fields.product_title_template'))
                        ->required()
                        ->maxLength(255),
                    Textarea::make('product_description_template')
                        ->label(__('shop.settings.seo.fields.product_description_template'))
                        ->required()
                        ->rows(2)
                        ->maxLength(500),
                    TextInput::make('category_title_template')
                        ->label(__('shop.settings.seo.fields.category_title_template'))
                        ->required()
                        ->maxLength(255),
                    Textarea::make('category_description_template')
                        ->label(__('shop.settings.seo.fields.category_description_template'))
                        ->required()
                        ->rows(2)
                        ->maxLength(500),
                ])->columns(2),
            Section::make(__('shop.settings.seo.sections.home'))->schema([
                TextInput::make('home_title')
                    ->label(__('shop.settings.seo.fields.home_title'))
                    ->maxLength(255),
                Textarea::make('home_description')
                    ->label(__('shop.settings.seo.fields.home_description'))
                    ->rows(2)
                    ->maxLength(500),
                FileUpload::make('og_image')
                    ->label(__('shop.settings.seo.fields.og_image'))
                    ->disk('public')
                    ->directory('settings')
                    ->visibility('public')
                    ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                    ->maxSize(2048),
            ])->columns(2),
            Section::make(__('shop.settings.seo.sections.technical'))->schema([
                Textarea::make('robots_txt')
                    ->label(__('shop.settings.seo.fields.robots_txt'))
                    ->required()
                    ->rows(6)
                    ->maxLength(5000)
                    ->extraInputAttributes(['class' => 'font-mono']),
                Textarea::make('verification_meta')
                    ->label(__('shop.settings.seo.fields.verification_meta'))
                    ->helperText(__('shop.settings.seo.hints.verification_meta'))
                    ->rows(3)
                    ->maxLength(2000)
                    ->extraInputAttributes(['class' => 'font-mono']),
                Textarea::make('head_code')
                    ->label(__('shop.settings.seo.fields.head_code'))
                    ->rows(4)
                    ->maxLength(10000)
                    ->extraInputAttributes(['class' => 'font-mono']),
                Textarea::make('body_code')
                    ->label(__('shop.settings.seo.fields.body_code'))
                    ->rows(4)
                    ->maxLength(10000)
                    ->extraInputAttributes(['class' => 'font-mono']),
            ])->columns(2),
        ])->columns(1);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        foreach (['home_title', 'home_description', 'head_code', 'body_code', 'verification_meta'] as $key) {
            $data[$key] = filled($data[$key] ?? null) ? $data[$key] : null;
        }

        return $data;
    }
}
