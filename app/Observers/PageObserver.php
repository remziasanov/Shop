<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Page;
use App\Services\Catalog\RedirectService;
use App\Services\Catalog\SlugService;
use App\Services\Content\HtmlSanitizer;
use App\Services\Content\MenuService;

final class PageObserver
{
    public function __construct(
        private readonly SlugService $slugs,
        private readonly RedirectService $redirects,
        private readonly HtmlSanitizer $sanitizer,
        private readonly MenuService $menus,
    ) {}

    public function saving(Page $page): void
    {
        if (! $page->exists || $page->isDirty('slug') || trim((string) $page->slug) === '') {
            $page->slug = $this->slugs->resolve('pages', $page->slug, (string) $page->title, $page->id);
        }

        if (! $page->exists || $page->isDirty('content')) {
            $html = $this->sanitizer->sanitize($page->content);
            $page->content = $html === '' ? null : $html;
        }
    }

    public function updated(Page $page): void
    {
        if ($page->wasChanged('slug')) {
            $this->redirects->record('/page/'.$page->getOriginal('slug'), '/page/'.$page->slug);
        }
    }

    public function saved(Page $page): void
    {
        $this->menus->flush();
    }

    public function deleted(Page $page): void
    {
        $this->menus->flush();
    }
}
