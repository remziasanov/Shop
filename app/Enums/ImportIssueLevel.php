<?php

declare(strict_types=1);

namespace App\Enums;

enum ImportIssueLevel: string
{
    case Error = 'error';
    case Warning = 'warning';

    public function label(): string
    {
        return __('import.issue_level.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
