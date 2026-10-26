<?php

declare(strict_types=1);

use App\Models\Page;
use App\Services\Content\DefaultPageTemplates;
use App\Settings\LegalSettings;

function defaultPagesMigration(): object
{
    return require base_path('database/migrations/2026_10_29_100003_seed_default_pages.php');
}

it('is idempotent when up() runs again and keeps edited pages and settings (SF-70)', function () {
    $legal = app(LegalSettings::class);
    $privacyId = $legal->privacy_page_id;
    $consentId = $legal->consent_page_id;
    Page::query()->where('slug', 'o-kompanii')->update(['content' => '<p>Своя страница</p>', 'is_active' => true]);

    defaultPagesMigration()->up();

    $legal = app(LegalSettings::class)->refresh();

    expect(Page::query()->whereIn('slug', array_column(DefaultPageTemplates::all(), 'slug'))->count())->toBe(5)
        ->and(Page::query()->where('slug', 'o-kompanii')->value('content'))->toBe('<p>Своя страница</p>')
        ->and($legal->privacy_page_id)->toBe($privacyId)
        ->and($legal->consent_page_id)->toBe($consentId);
});

it('removes only untouched template pages on down() and keeps edited ones', function () {
    Page::query()->where('slug', 'kontakty')->update(['content' => '<p>Наши контакты</p>']);

    defaultPagesMigration()->down();

    expect(Page::query()->where('slug', 'kontakty')->exists())->toBeTrue()
        ->and(Page::query()->where('slug', 'o-kompanii')->exists())->toBeFalse()
        ->and(Page::query()->where('slug', 'dostavka-i-oplata')->exists())->toBeFalse()
        ->and(Page::query()->where('slug', DefaultPageTemplates::PRIVACY_SLUG)->exists())->toBeFalse();

    // Pointers to the removed pages are cleared, then up() restores everything.
    expect(app(LegalSettings::class)->refresh()->privacy_page_id)->toBeNull();

    defaultPagesMigration()->up();

    expect(Page::query()->count())->toBe(5)
        ->and(app(LegalSettings::class)->refresh()->privacy_page_id)->not->toBeNull();
});
