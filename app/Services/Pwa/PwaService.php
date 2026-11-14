<?php

declare(strict_types=1);

namespace App\Services\Pwa;

use App\Services\Media\AppIconGenerator;
use App\Settings\AppearanceSettings;
use App\Support\ShopName;
use App\Support\TelegramMode;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * The installed-app side of the storefront (MB-20, MB-22, MB-24): is the app on, the manifest and the data of the head
 * tags. The manifest is cached; the cache key contains a digest of everything the manifest is built from, so a saved
 * setting (or a changed shop name) is picked up at once and no stale copy can be served.
 */
final class PwaService
{
    public const SHORT_NAME_MAX = 12;

    public const DEFAULT_THEME = '#2563eb';

    public const DEFAULT_BACKGROUND = '#ffffff';

    private const CACHE_PREFIX = 'pwa.manifest.';

    public function __construct(
        private readonly AppearanceSettings $settings,
        private readonly AppIconGenerator $icons,
        private readonly Cache $cache,
    ) {}

    /** MB-21: the app is on only with an icon and a generated icon set. */
    public function enabled(): bool
    {
        $s = $this->settings;

        return $s->pwa_enabled && $s->pwa_icon !== null && $s->pwa_icon !== '' && $s->pwa_icons_hash !== null;
    }

    /** MB-22: the install hint (menu item, iPhone sheet) is shown for an enabled app, outside Telegram. */
    public function installHintEnabled(): bool
    {
        return $this->enabled() && $this->settings->pwa_install_hint && ! TelegramMode::active();
    }

    /**
     * The manifest document, or null when the app is off (the route answers 404).
     *
     * @return array<string, mixed>|null
     */
    public function manifest(): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $key = self::CACHE_PREFIX.md5(json_encode([
            $this->name(),
            $this->shortName(),
            $this->themeColor(),
            $this->backgroundColor(),
            $this->settings->pwa_icons_hash,
        ], JSON_THROW_ON_ERROR));

        /** @var array<string, mixed> $manifest */
        $manifest = $this->cache->rememberForever($key, fn (): array => $this->build());

        return $manifest;
    }

    /**
     * Values for `<link rel="manifest">`, `theme-color` and `apple-touch-icon`, or null when the app is off.
     *
     * @return array{manifest: string, theme_color: string, apple_icon: string}|null
     */
    public function headData(): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        return [
            'manifest' => route('pwa.manifest'),
            'theme_color' => $this->themeColor(),
            'apple_icon' => $this->icons->urls((string) $this->settings->pwa_icons_hash)['apple'],
        ];
    }

    public function name(): string
    {
        $name = trim($this->settings->pwa_name);

        return $name !== '' ? $name : ShopName::get();
    }

    public function shortName(): string
    {
        $short = trim($this->settings->pwa_short_name);

        return mb_substr($short !== '' ? $short : $this->name(), 0, self::SHORT_NAME_MAX);
    }

    public function themeColor(): string
    {
        return $this->color($this->settings->pwa_theme_color, self::DEFAULT_THEME);
    }

    public function backgroundColor(): string
    {
        return $this->color($this->settings->pwa_background_color, self::DEFAULT_BACKGROUND);
    }

    /**
     * @return array<string, mixed>
     */
    private function build(): array
    {
        $urls = $this->icons->urls((string) $this->settings->pwa_icons_hash);

        return [
            'name' => $this->name(),
            'short_name' => $this->shortName(),
            'start_url' => '/?utm_source=pwa',
            'scope' => '/',
            'display' => 'standalone',
            'theme_color' => $this->themeColor(),
            'background_color' => $this->backgroundColor(),
            'lang' => 'ru',
            'icons' => [
                ['src' => $urls['icon-192'], 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $urls['icon-512'], 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $urls['maskable'], 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];
    }

    private function color(string $value, string $fallback): string
    {
        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) === 1 ? $value : $fallback;
    }
}
