<?php

declare(strict_types=1);

namespace App\Services\Themes;

use App\Enums\AuditAction;
use App\Models\User;
use App\Services\Users\AuditLogger;
use App\Settings\AppearanceSettings;
use InvalidArgumentException;

/**
 * Changes the theme settings: select a theme, save or reset overrides, apply a preview (TH-04, TH-26).
 * Every change is written to the admin journal; overrides are kept per theme id.
 */
final class ThemeManager
{
    public function __construct(
        private readonly AppearanceSettings $settings,
        private readonly ThemeRegistry $themes,
        private readonly ThemeResolver $resolver,
        private readonly ThemeCss $css,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Stored (sanitized) overrides of a theme.
     *
     * @return array{colors?: array<string, string>, fonts?: array<string, string>}
     */
    public function overridesFor(string $themeId): array
    {
        return $this->resolver->sanitizeOverrides($this->settings->theme_overrides[$themeId] ?? []);
    }

    public function select(User $actor, string $themeId): void
    {
        if (! $this->themes->has($themeId)) {
            throw new InvalidArgumentException('Unknown theme.');
        }

        $from = $this->settings->theme_id;

        if ($from === $themeId) {
            return;
        }

        $before = $this->activeResolved();
        $this->settings->theme_id = $themeId;
        $this->persist($before);
        $this->audit->record(AuditAction::ThemeChanged, $actor, null, ['from' => $from, 'to' => $themeId]);
    }

    /**
     * Saves the overrides of one theme; empty groups are removed.
     *
     * @param  array<string, mixed>  $raw
     * @return list<array{pair: string, ratio: float}> contrast warnings of the theme with these overrides (TH-08)
     */
    public function saveOverrides(User $actor, string $themeId, array $raw): array
    {
        $theme = $this->themes->find($themeId);

        if ($theme === null) {
            throw new InvalidArgumentException('Unknown theme.');
        }

        $old = $this->overridesFor($themeId);
        $new = $this->resolver->sanitizeOverrides($raw);

        $all = $this->settings->theme_overrides;

        if ($new === []) {
            unset($all[$themeId]);
        } else {
            $all[$themeId] = $new;
        }

        $before = $this->activeResolved();
        $this->settings->theme_overrides = $all;
        $this->persist($before);

        if (($old['colors'] ?? []) !== ($new['colors'] ?? [])) {
            $this->audit->record(AuditAction::ThemeColorsChanged, $actor, null, ['theme' => $themeId, 'from' => $old['colors'] ?? [], 'to' => $new['colors'] ?? []]);
        }

        if (($old['fonts'] ?? []) !== ($new['fonts'] ?? [])) {
            $this->audit->record(AuditAction::ThemeFontsChanged, $actor, null, ['theme' => $themeId, 'from' => $old['fonts'] ?? [], 'to' => $new['fonts'] ?? []]);
        }

        return $this->resolver->resolve($theme, $new)->warnings;
    }

    public function resetColors(User $actor, string $themeId): void
    {
        $overrides = $this->overridesFor($themeId);
        unset($overrides['colors']);
        $this->saveOverrides($actor, $themeId, $overrides);
    }

    public function resetFonts(User $actor, string $themeId): void
    {
        $overrides = $this->overridesFor($themeId);
        unset($overrides['fonts']);
        $this->saveOverrides($actor, $themeId, $overrides);
    }

    /**
     * «Применить» in a preview: the previewed theme becomes the active one with the previewed overrides.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function apply(User $actor, string $themeId, array $overrides): void
    {
        $this->select($actor, $themeId);
        $this->saveOverrides($actor, $themeId, $overrides);
    }

    /**
     * The active theme with its stored overrides, as it is before a change.
     */
    private function activeResolved(): ResolvedTheme
    {
        $theme = $this->themes->findOrDefault($this->settings->theme_id);

        return $this->resolver->resolve($theme, $this->overridesFor($theme->id));
    }

    /**
     * Drops the cached CSS of the state before the change (D-137), saves the settings and mirrors the active primary
     * and accent into the legacy fields.
     */
    private function persist(ResolvedTheme $before): void
    {
        $this->css->forget($before);
        $this->settings->save();
        $this->mirrorLegacyColors();
    }

    private function mirrorLegacyColors(): void
    {
        $resolved = $this->activeResolved();

        if ($this->settings->primary_color !== $resolved->colors['primary'] || $this->settings->accent_color !== $resolved->colors['accent']) {
            $this->settings->primary_color = $resolved->colors['primary'];
            $this->settings->accent_color = $resolved->colors['accent'];
            $this->settings->save();
        }
    }
}
