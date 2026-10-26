<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Settings\AppearanceSettings;
use App\Settings\GeneralSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;

/**
 * Shares storefront settings with the layout and its partials (SF-01, SF-02, SF-04).
 */
class StorefrontComposer
{
    private const DEFAULT_PRIMARY = '#2563eb';

    private const DEFAULT_ACCENT = '#f59e0b';

    public function __construct(
        private readonly GeneralSettings $general,
        private readonly AppearanceSettings $appearance,
    ) {}

    public function compose(View $view): void
    {
        $phones = array_values(array_filter($this->general->phones, fn ($phone) => trim($phone) !== ''));

        $view->with([
            'shopName' => $this->general->shop_name,
            'shopTagline' => $this->general->tagline,
            'shopLogoUrl' => $this->general->logo ? Storage::disk('public')->url($this->general->logo) : null,
            'shopFaviconUrl' => $this->general->favicon ? Storage::disk('public')->url($this->general->favicon) : null,
            'shopPhones' => $phones,
            'shopEmail' => $this->general->email,
            'shopAddress' => $this->general->address,
            'shopWorkingHours' => $this->general->working_hours,
            'shopFooterText' => $this->general->footer_text,
            'primaryColor' => $this->safeColor($this->appearance->primary_color, self::DEFAULT_PRIMARY),
            'accentColor' => $this->safeColor($this->appearance->accent_color, self::DEFAULT_ACCENT),
        ]);
    }

    /**
     * Only #rgb / #rrggbb values are allowed inside the inline <style> block.
     */
    private function safeColor(string $value, string $default): string
    {
        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) === 1 ? $value : $default;
    }
}
