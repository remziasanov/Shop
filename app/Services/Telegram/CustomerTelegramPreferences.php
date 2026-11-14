<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Enums\CustomerTelegramEvent;
use App\Models\Customer;
use App\Settings\TelegramSettings;

/**
 * The per-event Telegram switches of a customer (TN-05). `customers.telegram_notify_events` holds the switched-off
 * events (null = all on), so a new event is on for everyone without a data migration.
 */
class CustomerTelegramPreferences
{
    public function __construct(private readonly TelegramSettings $settings) {}

    /**
     * Events the customer can switch: only those the shop reports at all (TN-02).
     *
     * @return list<CustomerTelegramEvent>
     */
    public function switchable(): array
    {
        return array_values(array_filter(
            CustomerTelegramEvent::cases(),
            fn (CustomerTelegramEvent $event): bool => (bool) $this->settings->{$event->settingName()},
        ));
    }

    public function isEnabled(Customer $customer, CustomerTelegramEvent $event): bool
    {
        return ! in_array($event->value, $customer->telegram_notify_events ?? [], true);
    }

    /**
     * Saves the form: `$enabled` are the checked events. Events the shop does not offer keep their stored state.
     *
     * @param  list<string>  $enabled  event values
     */
    public function update(Customer $customer, array $enabled): void
    {
        $switchable = array_map(fn (CustomerTelegramEvent $event): string => $event->value, $this->switchable());
        $disabled = array_values(array_diff($customer->telegram_notify_events ?? [], $switchable));

        foreach ($switchable as $value) {
            if (! in_array($value, $enabled, true)) {
                $disabled[] = $value;
            }
        }

        $disabled = array_values(array_unique(array_intersect($disabled, CustomerTelegramEvent::values())));
        $customer->forceFill(['telegram_notify_events' => $disabled === [] ? null : $disabled])->save();
    }
}
