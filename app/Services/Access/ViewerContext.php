<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Enums\StorefrontSection;
use App\Exceptions\SalesException;
use App\Http\Middleware\ResolveViewer;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\User;
use App\Services\Catalog\CatalogVersion;
use App\Services\Users\PermissionService;
use App\Settings\CustomerSettings;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;

/**
 * The customer group of the current storefront visitor and what it may see (CG-16).
 *
 * The viewer is the signed-in customer of the `customer` guard with their group (an inactive group falls back to
 * «Розница»), otherwise «Гость». A signed-in staff member can preview the
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

    private ?Customer $customer = null;

    private bool $customerLoaded = false;

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
        $this->customer = null;
        $this->customerLoaded = false;
    }

    /**
     * Fix the viewer for code that runs outside a storefront request (queues, console) or in tests.
     */
    public function actingAs(?CustomerGroup $group): void
    {
        $this->explicit = $group;
        $this->group = null;
        $this->version = null;
        $this->customer = null;
        $this->customerLoaded = false;
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

    /**
     * The signed-in customer of the storefront (guard `customer`), or null for a guest. Null too for code that acts
     * as an explicit group and for a staff preview: they are not a customer's session. Read once per request.
     */
    public function customer(): ?Customer
    {
        if ($this->customerLoaded) {
            return $this->customer;
        }

        $this->customerLoaded = true;
        $this->customer = null;

        if ($this->explicit !== null || $this->previewGroupId() !== null || ! request()->hasSession()) {
            return null;
        }

        $user = Auth::guard('customer')->user();

        // The session middleware has dropped stale sessions already; a blocked account is not a customer here either.
        $this->customer = $user instanceof Customer && $user->canSignIn() ? $user : null;

        return $this->customer;
    }

    /**
     * CA-01, CG-12: whether the viewer may place an order, a reservation or an inquiry. A signed-in customer may; a
     * guest may when guest checkout is on and the «Гость» group does not require a login.
     */
    public function mayOrder(): bool
    {
        if ($this->customer() !== null) {
            return true;
        }

        return app(CustomerSettings::class)->allow_guest_checkout && ! $this->access()->requiresLoginToOrder;
    }

    /**
     * Server-side gate of checkout, reservation and inquiry (CA-01, CG-12).
     *
     * @throws SalesException when the viewer has to sign in first
     */
    public function assertMayOrder(): void
    {
        if (! $this->mayOrder()) {
            throw new SalesException(__('account.errors.login_required_to_order'));
        }
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

        $customer = $this->customer();

        if ($customer !== null) {
            $group = CustomerGroup::query()->find($customer->customer_group_id);

            // CG-16: a deactivated group sends its customers to «Розница».
            return $group !== null && $group->is_active ? $group : CustomerGroup::retail();
        }

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
