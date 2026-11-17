<?php

declare(strict_types=1);

namespace App\Services\Themes;

use App\Models\User;
use App\Services\Users\PermissionService;
use Illuminate\Contracts\Session\Session;

/**
 * Staff preview of a theme (TH-27…TH-30, D-138). The choice lives in the session of the staff member: theme id and
 * unsaved overrides. It is honoured only while the request also carries a signed-in active staff user (guard `web`)
 * with the right to change the appearance, so a forged session flag of a guest does nothing (TH-30).
 */
final class ThemePreview
{
    public const SESSION_KEY = 'theme_preview';

    public const PERMISSION = 'settings.appearance.update';

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly ThemeRegistry $themes,
        private readonly ThemeResolver $resolver,
    ) {}

    public function canPreview(?User $user): bool
    {
        return $user !== null && $user->is_active && $this->permissions->allows($user, self::PERMISSION);
    }

    /**
     * $embed marks a preview shown in the iframe of the admin overlay (D-210): the storefront hides its own bar there.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function start(Session $session, string $themeId, array $overrides = [], bool $embed = false): void
    {
        $theme = $this->themes->find($themeId);

        if ($theme === null) {
            return;
        }

        $state = ['theme_id' => $theme->id, 'overrides' => $this->resolver->sanitizeOverrides($overrides)];

        if ($embed) {
            $state['embed'] = true;
        }

        $session->put(self::SESSION_KEY, $state);
    }

    public function exit(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }

    /**
     * The stored preview, only for a user who may preview and only with a known theme id.
     *
     * @return array{theme_id: string, overrides: array<string, mixed>, embed: bool}|null
     */
    public function state(?Session $session, ?User $user): ?array
    {
        if ($session === null) {
            return null;
        }

        $raw = $session->get(self::SESSION_KEY);

        if (! is_array($raw) || ! is_string($raw['theme_id'] ?? null) || ! $this->themes->has($raw['theme_id']) || ! $this->canPreview($user)) {
            return null;
        }

        return ['theme_id' => $raw['theme_id'], 'overrides' => $this->resolver->sanitizeOverrides($raw['overrides'] ?? []), 'embed' => ($raw['embed'] ?? false) === true];
    }

    /**
     * Id of the previous / next theme in the registry order (`default` first), wrapping around. The id is always a
     * registered one (`state()` drops a session theme that is not in the registry), so the position always exists.
     */
    public function neighbour(string $themeId, int $step): string
    {
        $ids = array_keys($this->themes->all());
        $index = (int) array_search($themeId, $ids, true);

        return $ids[($index + $step + count($ids)) % count($ids)];
    }
}
