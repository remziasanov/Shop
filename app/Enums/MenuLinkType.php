<?php

declare(strict_types=1);

namespace App\Enums;

enum MenuLinkType: string
{
    case Category = 'category';
    case Page = 'page';
    case Url = 'url';

    public function label(): string
    {
        return __('admin_content.menu.link_types.'.$this->value);
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
