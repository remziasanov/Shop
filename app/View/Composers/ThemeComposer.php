<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Services\Themes\ActiveTheme;
use App\Services\Themes\ThemeCss;
use App\Services\Themes\ThemeRegistry;
use App\Services\Themes\ThemeResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Throwable;

/**
 * Shares the resolved theme with storefront views (TH-05): `$theme` (id, blocks, shape['surface'], extras, colors,
 * fonts), `$themeAssets` (css, preload, files) and `$themePreviewBar` (data of the staff preview bar or null).
 */
final class ThemeComposer
{
    /** @var array<string, mixed>|null */
    private ?array $data = null;

    private mixed $forRequest = null;

    public function __construct(
        private readonly ActiveTheme $active,
        private readonly ThemeCss $css,
        private readonly ThemeRegistry $themes,
    ) {}

    public function compose(View $view): void
    {
        $request = request();

        if ($this->data === null || $this->forRequest !== $request) {
            try {
                $theme = $this->active->current();
            } catch (Throwable) {
                // Never break a page (an error page included) because of the theme: fall back to the default one.
                $theme = app(ThemeResolver::class)->resolve($this->themes->default());
            }

            $this->data = [
                'theme' => $theme,
                'themeAssets' => $this->css->assets($theme),
                'themePreviewBar' => $this->isPreview() && ! $this->hideBar($request) ? [
                    'name' => $theme->name,
                    'id' => $theme->id,
                    'index' => $this->indexOf($theme->id),
                    'total' => count($this->themes->all()),
                ] : null,
            ];
            $this->forRequest = $request;
        }

        $view->with($this->data);
    }

    /** Position of the theme in the gallery: the active theme is always a registered one (or the default, which is the first). */
    private function indexOf(string $id): int
    {
        return (int) array_search($id, array_keys($this->themes->all()), true);
    }

    /**
     * D-210: a preview started from the admin overlay is shown in an iframe next to the overlay's own controls, so the
     * bar is left out there. Only when the browser says the document is framed: a top-level tab of the same session
     * (and a browser without Sec-Fetch-Dest) keeps the bar. The response stays private, no-store (TH-30).
     */
    private function hideBar(Request $request): bool
    {
        try {
            return $this->active->isEmbedPreview() && $request->headers->get('Sec-Fetch-Dest') === 'iframe';
        } catch (Throwable) {
            return false;
        }
    }

    private function isPreview(): bool
    {
        try {
            return $this->active->isPreview();
        } catch (Throwable) {
            return false;
        }
    }
}
