<?php

declare(strict_types=1);

namespace App\Enums;

enum ImportStatus: string
{
    case Uploaded = 'uploaded';
    case Analyzing = 'analyzing';
    case Analyzed = 'analyzed';
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('import.statuses.'.$this->value);
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

    /**
     * Terminal states: nothing runs for the import any more.
     */
    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::CompletedWithErrors, self::Failed, self::Cancelled], true);
    }

    /**
     * States in which the pipeline is working (or waiting for a worker).
     */
    public function isRunning(): bool
    {
        return in_array($this, [self::Analyzing, self::Queued, self::Processing], true);
    }
}
