<?php

declare(strict_types=1);

namespace App\Services\Themes;

use App\Models\User;
use App\Services\Users\PermissionService;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;

/**
 * Staff preview of a theme (TH-27…TH-30, D-138). The choice lives in the session of the staff member: theme id and
 * unsaved overrides. It is honoured only while the request also carries a signed-in active staff user (guard `web`)
 * with the right to change the appearance, so a forged session flag of a guest does nothing (TH-30).
 */
final class ThemePreview
{
    public const SESSION_KEY = 'theme_preview';

    /** TEMPORARY (stage 21, remove in stage 23): session key of the block variants of the service theme. */
    public const BLOCKS_KEY = 'theme_preview_blocks';

    /** Allowed variants per block slot (TH-10…TH-13). */
    public const BLOCK_SETS = ['header' => ['H1', 'H2', 'H3', 'H4'], 'card' => ['C1', 'C2', 'C3', 'C4', 'C5', 'C6'], 'hero' => ['G1', 'G2', 'G3', 'G4'], 'listing' => ['L1', 'L2', 'L3']];

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
     * @param  array<string, mixed>  $overrides
     */
    public function start(Session $session, string $themeId, array $overrides = []): void
    {
        $theme = $this->themes->findForPreview($themeId);

        if ($theme === null) {
            return;
        }

        $session->put(self::SESSION_KEY, ['theme_id' => $theme->id, 'overrides' => $this->resolver->sanitizeOverrides($overrides)]);
    }

    public function exit(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
        $session->forget(self::BLOCKS_KEY);
    }

    /**
     * TEMPORARY (stage 21, remove in stage 23). With THEME_DEBUG on, a staff member with the right opens the service
     * theme by `?theme=debug-blocks` and picks its variants by `?blocks=H3,C5,G2,L3`. The values are validated against
     * BLOCK_SETS (anything else is dropped), live in the staff session only and never reach a visitor.
     */
    public function handleDebugRequest(Request $request, ?User $user): void
    {
        if (! $this->themes->debugEnabled() || ! $request->hasSession() || ! $this->canPreview($user)) {
            return;
        }

        $session = $request->session();

        if ($request->query('theme') === ThemeRegistry::DEBUG_ID) {
            $this->start($session, ThemeRegistry::DEBUG_ID);
        }

        if (is_string($request->query('blocks')) && ($session->get(self::SESSION_KEY)['theme_id'] ?? null) === ThemeRegistry::DEBUG_ID) {
            $session->put(self::BLOCKS_KEY, $this->parseBlocks($request->query('blocks')));
        }
    }

    /**
     * @return array<string, string> slot => variant, only valid ones
     */
    public function parseBlocks(string $raw): array
    {
        $out = [];

        foreach (explode(',', mb_substr($raw, 0, 64)) as $token) {
            $token = strtoupper(trim($token));

            foreach (self::BLOCK_SETS as $slot => $variants) {
                if (in_array($token, $variants, true)) {
                    $out[$slot] = $token;
                }
            }
        }

        return $out;
    }

    /**
     * The block variants of the service theme chosen in this session (empty for any other theme).
     *
     * @return array<string, string>
     */
    public function debugBlocks(?Session $session): array
    {
        $stored = $session?->get(self::BLOCKS_KEY);

        return is_array($stored) ? $this->parseBlocks(implode(',', array_map('strval', $stored))) : [];
    }

    /**
     * The stored preview, only for a user who may preview and only with a known theme id.
     *
     * @return array{theme_id: string, overrides: array<string, mixed>}|null
     */
    public function state(?Session $session, ?User $user): ?array
    {
        if ($session === null) {
            return null;
        }

        $raw = $session->get(self::SESSION_KEY);

        if (! is_array($raw) || ! is_string($raw['theme_id'] ?? null) || ! $this->themes->hasForPreview($raw['theme_id']) || ! $this->canPreview($user)) {
            return null;
        }

        return ['theme_id' => $raw['theme_id'], 'overrides' => $this->resolver->sanitizeOverrides($raw['overrides'] ?? [])];
    }

    /**
     * Id of the previous / next theme in the registry order (`default` first), wrapping around.
     */
    public function neighbour(string $themeId, int $step): string
    {
        $ids = array_keys($this->themes->all());
        $index = array_search($themeId, $ids, true);
        $index = $index === false ? 0 : $index;

        return $ids[($index + $step + count($ids)) % count($ids)];
    }
}
