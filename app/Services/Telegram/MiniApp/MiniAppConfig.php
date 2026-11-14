<?php

declare(strict_types=1);

namespace App\Services\Telegram\MiniApp;

use App\Settings\TelegramSettings;
use Illuminate\Support\Facades\Cache;

/**
 * The Mini App view of {@see TelegramSettings} (TG-01, TM-01, TM-07, TM-09): one place that answers «is the customer
 * Mini App on», «which bot», «which colours» and builds the bot links.
 */
final class MiniAppConfig
{
    public const COLORS_SHOP = 'shop';

    public const COLORS_TELEGRAM = 'telegram';

    public function __construct(private readonly TelegramSettings $settings) {}

    /** The Mini App works: switched on and a bot token exists. */
    public function enabled(): bool
    {
        return $this->settings->miniapp_enabled && $this->botToken() !== '';
    }

    public function botToken(): string
    {
        return trim((string) $this->settings->bot_token);
    }

    public function botUsername(): ?string
    {
        $name = ltrim(trim((string) $this->settings->bot_username), '@');

        return $name !== '' ? $name : null;
    }

    /** Seconds an `auth_date` of a customer session stays valid (TM-02). */
    public function initDataTtl(): int
    {
        return max(60, $this->settings->initdata_ttl_customer);
    }

    public function autologinEnabled(): bool
    {
        return $this->settings->autologin_enabled;
    }

    /** `shop` (shop theme colours) or `telegram` (TM-07). */
    public function colorsMode(): string
    {
        return $this->settings->miniapp_colors === self::COLORS_TELEGRAM ? self::COLORS_TELEGRAM : self::COLORS_SHOP;
    }

    /** TG-03: `t.me/{bot}?startapp=check` has been seen working. */
    public function startappVerified(): bool
    {
        return $this->settings->startapp_verified_at !== null && $this->settings->startapp_verified_at !== '';
    }

    public function botUrl(): ?string
    {
        $name = $this->botUsername();

        return $name !== null ? 'https://t.me/'.$name : null;
    }

    /**
     * `https://t.me/{bot}?startapp={param}` (TM-09); null while the bot is unknown.
     */
    public function startappLink(string $param): ?string
    {
        $base = $this->botUrl();

        return $base !== null ? $base.'?startapp='.rawurlencode($param) : null;
    }

    public const SITE_LINK_CACHE_KEY = 'telegram:site-link-base';

    /**
     * «Открыть в Telegram» on the site (TM-09): the Mini App is on and the startapp links were verified (TG-03). A site
     * page must not read the settings table for it (NF-02), so the base address is kept in the cache and rewritten
     * whenever the Telegram settings are saved ({@see self::rememberSiteLinkBase()}); without the entry there is no link.
     */
    public function siteLinkFor(string $param): ?string
    {
        $base = Cache::get(self::SITE_LINK_CACHE_KEY);

        return $base !== null && $base !== '' ? $base.'?startapp='.rawurlencode($param) : null;
    }

    /**
     * Stores the base of the site links (an empty string while they must not be shown).
     */
    public function rememberSiteLinkBase(): void
    {
        $verified = $this->enabled() && $this->startappVerified();

        Cache::forever(self::SITE_LINK_CACHE_KEY, $verified ? (string) $this->botUrl() : '');
    }
}
