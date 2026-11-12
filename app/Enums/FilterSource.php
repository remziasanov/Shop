<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Source of a configurable category filter (FL-05): a characteristic or a variant option (VR-19, stage 11).
 */
enum FilterSource: string
{
    case Attribute = 'attribute';
    case Option = 'option';

    public function label(): string
    {
        return __('attributes.filter_sources.'.$this->value);
    }
}
