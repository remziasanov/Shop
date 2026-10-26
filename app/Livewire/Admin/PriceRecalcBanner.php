<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Services\Pricing\RecalculationProgress;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Admin banner «Пересчёт цен: N из M» (PR-05). Renders nothing, and does not poll, while idle.
 */
class PriceRecalcBanner extends Component
{
    public function render(RecalculationProgress $progress): View
    {
        return view('livewire.admin.price-recalc-banner', ['progress' => $progress->current()]);
    }
}
