<?php

declare(strict_types=1);

namespace App\Services\Notifications\Messages;

use App\Filament\Resources\WholesaleApplications\WholesaleApplicationResource;
use App\Models\WholesaleApplication;
use App\Services\Notifications\HtmlEscaper;

/**
 * Telegram text about a new wholesale application (CG-21). Every interpolated value is escaped; the comment of the
 * customer is not included (it is read in the admin panel).
 */
final class WholesaleApplicationMessage
{
    /** Longest customer-provided single value in characters. */
    private const VALUE_LIMIT = 200;

    /**
     * @param  list<string>  $warnings  duplicate-INN warnings for the manager (CG-24)
     */
    public function render(WholesaleApplication $application, array $warnings = []): string
    {
        $lines = [
            '<b>'.$this->e(__('wholesale.telegram.title')).'</b>',
            '',
            $this->e(__('wholesale.telegram.company')).': '.$this->e($this->clip($application->company_name)),
            $this->e(__('wholesale.telegram.inn')).': '.$this->e($this->clip($application->inn)),
            $this->e(__('wholesale.telegram.contact')).': '.$this->e($this->clip($application->contact_name)),
            $this->e(__('wholesale.telegram.phone')).': '.$this->e($this->clip($application->phone)),
        ];

        foreach (array_slice($warnings, 0, 3) as $warning) {
            $lines[] = '⚠ '.$this->e($warning);
        }

        $lines[] = '';
        $lines[] = '<a href="'.$this->e(self::adminUrl($application)).'">'.$this->e(__('wholesale.telegram.open')).'</a>';

        return implode("\n", $lines);
    }

    public static function adminUrl(WholesaleApplication $application): string
    {
        return WholesaleApplicationResource::getUrl('view', ['record' => $application]);
    }

    private function e(string|int|null $value): string
    {
        return HtmlEscaper::e($value);
    }

    private function clip(?string $value): string
    {
        $value = (string) $value;

        return mb_strlen($value) > self::VALUE_LIMIT ? mb_substr($value, 0, self::VALUE_LIMIT - 1).'…' : $value;
    }
}
