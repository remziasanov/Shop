<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Themes\ThemeManager;
use App\Services\Themes\ThemePreview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Buttons of the preview bar (TH-27): previous / next theme, apply, exit. POST + CSRF; every action re-checks the right
 * of the signed-in staff member (guard `web`), so the session flag alone does nothing (TH-30).
 */
class ThemePreviewController extends Controller
{
    public function __construct(private readonly ThemePreview $previews) {}

    public function step(Request $request): RedirectResponse
    {
        $state = $this->state($request);
        $step = $request->input('direction') === 'prev' ? -1 : 1;
        $next = $this->previews->neighbour($state['theme_id'], $step);

        // Another theme is shown with its own saved overrides, not with the colors of the one just left.
        $this->previews->start($request->session(), $next, app(ThemeManager::class)->overridesFor($next));

        return redirect()->back(fallback: route('home'));
    }

    public function apply(Request $request, ThemeManager $manager): RedirectResponse
    {
        $state = $this->state($request);
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $manager->apply($user, $state['theme_id'], $state['overrides']);
        $this->previews->exit($request->session());

        return redirect()->route('home');
    }

    public function exit(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $this->previews->canPreview($user), 403);

        $this->previews->exit($request->session());

        return redirect()->route('home');
    }

    /**
     * @return array{theme_id: string, overrides: array<string, mixed>}
     */
    private function state(Request $request): array
    {
        $user = $request->user();
        $state = $this->previews->state($request->session(), $user instanceof User ? $user : null);
        abort_if($state === null, 403);

        return $state;
    }
}
