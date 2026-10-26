<?php

declare(strict_types=1);

namespace App\Enums;

enum MediaSource: string
{
    case None = 'none';
    case Zip = 'zip';
    case ServerDir = 'server_dir';

    public function label(): string
    {
        return __('import.media_source.'.$this->value);
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
