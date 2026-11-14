<?php

declare(strict_types=1);

namespace App\Services\Themes;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The theme list (TH-01, TH-02): `default` plus the themes of `resources/themes/themes.json`, shop-type groups and
 * lookup by id. An unknown id falls back to `default` with a warning in the log.
 */
final class ThemeRegistry
{
    public const DEFAULT_ID = 'default';

    /** @var array<string, Theme>|null */
    private ?array $themes = null;

    /** @var list<array{id: string, name: string}>|null */
    private ?array $groups = null;

    public function __construct(private readonly ?string $dir = null) {}

    /**
     * @return array<string, Theme> default first, then by number
     */
    public function all(): array
    {
        $this->load();

        return $this->themes;
    }

    /**
     * Shop types in the order of the registry.
     *
     * @return list<array{id: string, name: string}>
     */
    public function groups(): array
    {
        $this->load();

        return $this->groups;
    }

    /**
     * @return array<string, list<Theme>> group id => themes (without `default`)
     */
    public function grouped(): array
    {
        $out = [];

        foreach ($this->groups() as $group) {
            $out[$group['id']] = [];
        }

        foreach ($this->all() as $theme) {
            if ($theme->group !== null && isset($out[$theme->group])) {
                $out[$theme->group][] = $theme;
            }
        }

        return $out;
    }

    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    public function find(string $id): ?Theme
    {
        return $this->all()[$id] ?? null;
    }

    public function default(): Theme
    {
        return $this->all()[self::DEFAULT_ID];
    }

    /**
     * TH-02: an unknown id never breaks the storefront.
     */
    public function findOrDefault(?string $id): Theme
    {
        $theme = $id === null ? null : $this->find($id);

        if ($theme === null) {
            $safe = is_string($id) ? mb_substr($id, 0, 64) : null;

            // Once per id per day: the warning must not flood the log on every request.
            if (Cache::add('theme-unknown-logged:'.md5((string) $safe).':'.date('Ymd'), 1, 86400)) {
                Log::warning('Unknown theme id in settings, falling back to default.', ['theme_id' => $safe]);
            }

            return $this->default();
        }

        return $theme;
    }

    /**
     * Fingerprint of the registry files; part of the generated CSS cache key.
     */
    public function version(): string
    {
        $dir = $this->dir ?? resource_path('themes');

        return substr(md5(md5_file($dir.'/themes.json').md5_file($dir.'/default.json').md5_file($dir.'/fonts.json')), 0, 10);
    }

    private function load(): void
    {
        if ($this->themes !== null) {
            return;
        }

        $dir = $this->dir ?? resource_path('themes');
        $registry = json_decode((string) file_get_contents($dir.'/themes.json'), true, 512, JSON_THROW_ON_ERROR);
        $default = json_decode((string) file_get_contents($dir.'/default.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->groups = array_values(array_map(
            fn (array $group): array => ['id' => (string) $group['id'], 'name' => (string) $group['name']],
            (array) ($registry['groups'] ?? []),
        ));

        $this->themes = [self::DEFAULT_ID => Theme::fromArray($default)];

        foreach ((array) $registry['themes'] as $data) {
            $this->themes[(string) $data['id']] = Theme::fromArray($data);
        }
    }
}
