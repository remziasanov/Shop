<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\AdminOnlySettingsPage;
use App\Models\Page;
use App\Settings\LegalSettings;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

class ManageLegalSettings extends SettingsPage
{
    use AdminOnlySettingsPage;

    protected static string $settings = LegalSettings::class;

    private ?string $previousSignature = null;

    protected static string $translationKey = 'legal';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 70;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('seller_details')
                ->label(__('shop.settings.legal.fields.seller_details'))
                ->rows(4)
                ->maxLength(2000)
                ->columnSpanFull(),
            Select::make('privacy_page_id')
                ->label(__('shop.settings.legal.fields.privacy_page_id'))
                ->options(fn (): array => Page::query()->orderBy('title')->pluck('title', 'id')->all())
                ->searchable()
                ->native(false),
            Select::make('consent_page_id')
                ->label(__('shop.settings.legal.fields.consent_page_id'))
                ->options(fn (): array => Page::query()->orderBy('title')->pluck('title', 'id')->all())
                ->searchable()
                ->native(false),
            Textarea::make('consent_text')
                ->label(__('shop.settings.legal.fields.consent_text'))
                ->helperText(__('shop.settings.legal.hints.check_with_lawyer'))
                ->required()
                ->rows(2)
                ->maxLength(1000)
                ->columnSpanFull(),
            Toggle::make('cookie_banner_enabled')
                ->label(__('shop.settings.legal.fields.cookie_banner_enabled'))
                ->columnSpanFull(),
            Textarea::make('cookie_banner_text')
                ->label(__('shop.settings.legal.fields.cookie_banner_text'))
                ->required()
                ->rows(2)
                ->maxLength(1000)
                ->columnSpanFull(),
            // CX-10, CX-11: the seller's bank requisites turn the PDF «Бланк заказа» into «Счёт на оплату».
            Section::make(__('shop.settings.legal.sections.bank'))
                ->description(__('shop.settings.legal.hints.bank'))
                ->schema([
                    TextInput::make('bank_name')
                        ->label(__('shop.settings.legal.fields.bank_name'))
                        ->maxLength(255),
                    TextInput::make('bik')
                        ->label(__('shop.settings.legal.fields.bik'))
                        ->regex('/^\d{9}$/')
                        ->validationMessages(['regex' => __('shop.settings.legal.hints.digits', ['count' => 9])])
                        ->inputMode('numeric'),
                    TextInput::make('account')
                        ->label(__('shop.settings.legal.fields.account'))
                        ->regex('/^\d{20}$/')
                        ->validationMessages(['regex' => __('shop.settings.legal.hints.digits', ['count' => 20])])
                        ->inputMode('numeric'),
                    TextInput::make('corr_account')
                        ->label(__('shop.settings.legal.fields.corr_account'))
                        ->regex('/^\d{20}$/')
                        ->validationMessages(['regex' => __('shop.settings.legal.hints.digits', ['count' => 20])])
                        ->inputMode('numeric'),
                    TextInput::make('vat_note')
                        ->label(__('shop.settings.legal.fields.vat_note'))
                        ->helperText(__('shop.settings.legal.hints.vat_note'))
                        ->maxLength(255),
                    FileUpload::make('signature_image')
                        ->label(__('shop.settings.legal.fields.signature_image'))
                        ->helperText(__('shop.settings.legal.hints.signature_image'))
                        ->disk('local')
                        ->directory('legal')
                        ->visibility('private')
                        ->acceptedFileTypes(['image/png', 'image/jpeg'])
                        ->maxSize(1024),
                    Textarea::make('invoice_footer')
                        ->label(__('shop.settings.legal.fields.invoice_footer'))
                        ->helperText(__('shop.settings.legal.hints.invoice_footer'))
                        ->rows(3)
                        ->maxLength(1000)
                        ->columnSpanFull(),
                ])->columns(2),
        ]);
    }

    protected function beforeSave(): void
    {
        $previous = app(LegalSettings::class)->signature_image;
        $this->previousSignature = filled($previous) ? $previous : null;
    }

    /**
     * A replaced or cleared signature image leaves the private disk.
     */
    protected function afterSave(): void
    {
        $current = app(static::getSettings())->signature_image;

        if ($this->previousSignature !== null
            && $this->previousSignature !== $current
            && str_starts_with($this->previousSignature, 'legal/')
            && ! str_contains($this->previousSignature, '..')) {
            Storage::disk('local')->delete($this->previousSignature);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['seller_details'] = filled($data['seller_details'] ?? null) ? $data['seller_details'] : null;

        foreach (['privacy_page_id', 'consent_page_id'] as $field) {
            $data[$field] = filled($data[$field] ?? null) ? (int) $data[$field] : null;
        }

        foreach (['bank_name', 'bik', 'account', 'corr_account', 'vat_note', 'signature_image'] as $field) {
            $data[$field] = filled($data[$field] ?? null) ? trim((string) $data[$field]) : null;
        }

        $data['invoice_footer'] = (string) ($data['invoice_footer'] ?? '');

        return $data;
    }
}
