<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Enums\StorefrontSection;
use App\Exceptions\SalesException;
use App\Http\Middleware\ResolveViewer;
use App\Models\CustomerGroup;
use App\Models\User;
use App\Services\Catalog\CatalogVersion;
use App\Services\Users\PermissionService;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The customer group of the current storefront visitor and what it may see (CG-16).
 *
 * Stage 8: the viewer is always «Гость». Stage 9 plugs the `customer` guard into {@see resolveGroup()}: a signed-in
 * customer gets their group (an inactive group falls back to «Розница»). A signed-in staff member can preview the
 * storefront as any group ("Просмотреть витрину как…", CG-18): that is a session flag of the staff user, not a login
 * as a customer. Queues and console code pass the group explicitly ({@see actingAs()}) or use {@see CatalogAccess}.
 *
 * Resolved lazily and memoized per request and catalog version, so Livewire requests (which skip some middleware)
 * see the same viewer. {@see ResolveViewer} resets the per-request state and adds the private/noindex headers.
 */
final class ViewerContext
{
    /** Session key of the staff preview (the id of the group to look at the storefront as). */
    public const PREVIEW_KEY = 'viewer_preview_group_id';

    private ?CustomerGroup $group = null;

    private ?string $version = null;

    private ?CustomerGroup $explicit = null;

    private bool $private = false;

    public function __construct(
        private readonly CatalogAccess $catalog,
        private readonly PermissionService $permissions,
    ) {}

    /**
     * Start of a request: forget the previous request's group, flags and explicit viewer (long-lived processes, tests).
     */
    public function beginRequest(): void
    {
        // Per-request memos of the catalog version and the guest group (a worker or a test may have kept old ones).
        CatalogVersion::reset();
        $this->catalog->reset();
        $this->group = null;
        $this->version = null;
        $this->explicit = null;
        $this->private = false;
    }

    /**
     * Fix the viewer for code that runs outside a storefront request (queues, console) or in tests.
     */
    public function actingAs(?CustomerGroup $group): void
    {
        $this->explicit = $group;
        $this->group = null;
        $this->version = null;
    }

    public function group(): CustomerGroup
    {
        $version = CatalogVersion::current();

        if ($this->group === null || $this->version !== $version) {
            $this->group = $this->resolveGroup();
            $this->version = $version;
        }

        return $this->group;
    }

    public function access(): AccessSet
    {
        return $this->catalog->for($this->group());
    }

    public function guestAccess(): AccessSet
    {
        return $this->catalog->guest();
    }

    public function isGuest(): bool
    {
        return $this->group()->isGuest();
    }

    /**
     * A staff member looks at the storefront as another group.
     */
    public function isPreview(): bool
    {
        return $this->explicit === null && $this->previewGroupId() !== null;
    }

    /**
     * A staff preview never buys: the cart, checkout, reservations and inquiries are closed to it (CG-18).
     *
     * @throws SalesException
     */
    public function assertNotPreview(): void
    {
        if ($this->isPreview()) {
            throw new SalesException(__('customer_groups.storefront.preview_blocked'));
        }
    }

    /**
     * Opens the section or ends the request: a guest is sent to the sign-in page (stage 8: a stub) with a message,
     * a signed-in customer gets a 404 (CG-04).
     *
     * @throws HttpResponseException
     */
    public function requireSection(StorefrontSection $section): void
    {
        if ($this->access()->allowsSection($section)) {
            return;
        }

        if ($this->isGuest()) {
            throw new HttpResponseException(
                redirect()->route('customer.login')->with('access_message', __('customer_groups.storefront.section_hidden')),
            );
        }

        abort(404);
    }

    /**
     * CG-15: a page the viewer may see but a guest may not is not for search engines and not for shared caches.
     * Marks the response private when it applies (the middleware adds the headers).
     *
     * @param  Closure(AccessSet): bool  $guestSees  whether the «Гость» group may see the page
     * @return bool true when the page must carry noindex
     */
    public function noindexUnlessGuestSees(Closure $guestSees): bool
    {
        if ($this->isGuest() || $guestSees($this->guestAccess())) {
            return false;
        }

        $this->private = true;

        return true;
    }

    public function isPrivate(): bool
    {
        return $this->private;
    }

    private function resolveGroup(): CustomerGroup
    {
        if ($this->explicit !== null) {
            return $this->explicit;
        }

        $previewId = $this->previewGroupId();

        if ($previewId !== null) {
            $group = CustomerGroup::query()->find($previewId);

            if ($group !== null) {
                return $group;
            }
        }

        // Stage 9: a signed-in customer (guard `customer`) is looked up here; an inactive group means «Розница».
        return $this->catalog->guestGroup();
    }

    private function previewGroupId(): ?int
    {
        $request = request();

        if (! $request->hasSession()) {
            return null;
        }

        $id = $request->session()->get(self::PREVIEW_KEY);

        if (! is_numeric($id)) {
            return null;
        }

        // The right is checked on every request, not only when the preview starts: a role that lost
        // `customer_groups.view` (or a deactivated account) ends the preview at once.
        $user = $request->user();

        return $user instanceof User && $user->is_active && $this->permissions->allows($user, 'customer_groups.view') ? (int) $id : null;
    }
}
