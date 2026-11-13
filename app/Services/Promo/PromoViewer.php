<?php

declare(strict_types=1);

namespace App\Services\Promo;

use App\Services\Access\ViewerContext;

/**
 * Who uses the code: the customer group, the signed-in customer (and whether their e-mail is verified) and, for the
 * per-customer limit of a guest, the contact data of the order (PM-10). A guest's contact is only known at checkout.
 */
final readonly class PromoViewer
{
    public function __construct(
        public ?int $groupId,
        public ?int $customerId = null,
        public bool $emailVerified = false,
        public ?string $email = null,
        public ?string $phone = null,
    ) {}

    public static function fromContext(ViewerContext $viewer, ?string $email = null, ?string $phone = null): self
    {
        $group = $viewer->group();
        $customer = $viewer->customer();

        return new self(
            $group->exists ? $group->id : null,
            $customer?->id,
            $customer?->hasVerifiedEmail() ?? false,
            $email,
            $phone,
        );
    }

    /**
     * PM-04: «только для зарегистрированных» means a signed-in customer with a verified e-mail.
     */
    public function isRegistered(): bool
    {
        return $this->customerId !== null && $this->emailVerified;
    }

    /**
     * HMAC-SHA256 of lower(email) + "|" + phone with the application key; null when neither is known (PM-10).
     */
    public function customerHash(): ?string
    {
        return PromoCustomerHash::make($this->email, $this->phone);
    }

    public function withContact(?string $email, ?string $phone): self
    {
        return new self($this->groupId, $this->customerId, $this->emailVerified, $email, $phone);
    }
}
