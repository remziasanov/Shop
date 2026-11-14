<?php

declare(strict_types=1);

namespace App\Services\Themes;

use App\Models\User;
use App\Settings\AppearanceSettings;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The theme of the current request: the saved one for everybody, the preview one for an authorized staff member
 * who started a preview (TH-27, TH-30). Resolved once per request.
 */
final class ActiveTheme
{
    private ?ResolvedTheme $resolved = null;

    private bool $preview = false;

    private ?Request $forRequest = null;

    public function __construct(
        private readonly ThemeRegistry $themes,
        private readonly ThemeResolver $resolver,
        private readonly ThemePreview $previews,
    ) {}

    public function current(): ResolvedTheme
    {
        $request = request();

        if ($this->resolved === null || $this->forRequest !== $request) {
            $this->forRequest = $request;
            $this->resolved = $this->resolve($request);
        }

        return $this->resolved;
    }

    /**
     * Whether this request shows a staff preview (never cached, never indexed).
     */
    public function isPreview(): bool
    {
        $this->current();

        return $this->preview;
    }

    /**
     * The saved (public) theme.
     */
    public function saved(): ResolvedTheme
    {
        // The settings are read lazily: an error page must render even when the database is down (SF-07).
        try {
            $appearance = app(AppearanceSettings::class);
            $theme = $this->themes->findOrDefault($appearance->theme_id);

            return $this->resolver->resolve($theme, (array) ($appearance->theme_overrides[$theme->id] ?? []));
        } catch (QueryException) {
            return $this->resolver->resolve($this->themes->default());
        }
    }

    /**
     * Part of the home page cache key (TH-12): theme id and a hash of its overrides.
     */
    public function cacheKey(): string
    {
        $theme = $this->current();

        // The service theme (stage 21, temporary) changes its blocks per request: they are part of the key.
        $blocks = $theme->id === ThemeRegistry::DEBUG_ID ? '-'.substr(md5((string) json_encode($theme->blocks)), 0, 8) : '';

        return $theme->id.'-'.ThemeCss::overridesHash($theme->overrides).$blocks;
    }

    private function resolve(Request $request): ResolvedTheme
    {
        $this->preview = false;

        try {
            $user = $request->hasSession() ? Auth::guard('web')->user() : null;
            $this->previews->handleDebugRequest($request, $user instanceof User ? $user : null);
            $state = $this->previews->state($request->hasSession() ? $request->session() : null, $user instanceof User ? $user : null);
        } catch (QueryException) {
            $state = null;
        }

        if ($state !== null) {
            $this->preview = true;

            $resolved = $this->resolver->resolve($this->themes->findForPreview($state['theme_id']) ?? $this->themes->findOrDefault($state['theme_id']), $state['overrides']);

            // TEMPORARY (stage 21, remove in stage 23): the block variants of the service theme come from the staff session.
            if ($resolved->id === ThemeRegistry::DEBUG_ID && $request->hasSession()) {
                $blocks = $this->previews->debugBlocks($request->session());

                if ($blocks !== []) {
                    return $resolved->withBlocks(array_merge($resolved->blocks, $blocks));
                }
            }

            return $resolved;
        }

        return $this->saved();
    }
}
