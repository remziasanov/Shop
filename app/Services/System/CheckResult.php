<?php

declare(strict_types=1);

namespace App\Services\System;

use App\Enums\CheckStatus;

final readonly class CheckResult
{
    public function __construct(
        public string $key,
        public string $label,
        public CheckStatus $status,
        public string $value,
        public ?string $hint = null,
    ) {}

    /**
     * @return array{key: string, label: string, status: string, value: string, hint: ?string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'status' => $this->status->value,
            'value' => $this->value,
            'hint' => $this->hint,
        ];
    }
}
