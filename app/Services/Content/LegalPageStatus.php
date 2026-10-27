<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\Page;
use App\Settings\LegalSettings;
use Throwable;

/**
 * Detects privacy / consent pages that still carry the template marker (D-055): the owner must replace the text.
 */
final class LegalPageStatus
{
    /**
     * @return list<string> titles of the pages that still contain the template marker
     */
    public function pendingTitles(): array
    {
        $ids = [];

        try {
            $legal = app(LegalSettings::class);
            $ids = array_values(array_filter([$legal->privacy_page_id, $legal->consent_page_id]));
        } catch (Throwable) {
            // Settings are not migrated yet: fall back to the default slugs only.
        }

        return Page::query()
            ->where(function ($query) use ($ids): void {
                $query->whereIn('slug', [DefaultPageTemplates::PRIVACY_SLUG, DefaultPageTemplates::CONSENT_SLUG]);

                if ($ids !== []) {
                    $query->orWhereIn('id', $ids);
                }
            })
            ->where('content', 'like', '%'.DefaultPageTemplates::MARKER.'%')
            ->orderBy('id')
            ->pluck('title')
            ->all();
    }
}
