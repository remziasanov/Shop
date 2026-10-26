<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\MenuLinkType;
use App\Models\MenuItem;
use App\Services\Content\MenuService;

final class MenuItemObserver
{
    public function __construct(private readonly MenuService $menus) {}

    public function saving(MenuItem $item): void
    {
        // Only the field of the chosen link type is kept.
        if ($item->link_type !== MenuLinkType::Category) {
            $item->category_id = null;
        }

        if ($item->link_type !== MenuLinkType::Page) {
            $item->page_id = null;
        }

        if ($item->link_type !== MenuLinkType::Url) {
            $item->url = null;
        }
    }

    public function saved(MenuItem $item): void
    {
        $this->menus->flush();
    }

    public function deleted(MenuItem $item): void
    {
        $this->menus->flush();
    }
}
