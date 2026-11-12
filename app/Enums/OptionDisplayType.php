<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How an option is chosen on the product card (VR-01, VR-09): a colour swatch, buttons or a drop-down list.
 */
enum OptionDisplayType: string
{
    case Color = 'color';
    case Button = 'button';
    case Select = 'select';

    public function label(): string
    {
        return __('variants.display_types.'.$this->value);
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
