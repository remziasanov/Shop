<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Enums\CustomerTelegramEvent;
use Illuminate\Validation\Rule;

/**
 * TN-05: the event switches of the Telegram block in the cabinet «Уведомления» (the checked events are the enabled ones).
 */
class TelegramEventsRequest extends AccountRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'events' => ['nullable', 'array'],
            'events.*' => ['string', Rule::enum(CustomerTelegramEvent::class)],
        ];
    }
}
