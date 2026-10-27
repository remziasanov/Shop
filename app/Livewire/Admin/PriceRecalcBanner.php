<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Services\Pricing\RecalculationProgress;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Admin banner «Пересчёт цен: N из M» (PR-05). Renders nothing while idle, but polls slowly (30 s, one cache read) to notice a recalculation started after
 * the page was opened; polls every 5 s while a recalculation is running.
 */
class PriceRecalcBanner extends Component
{
    public function render(RecalculationProgress $progress): View
    {
        return view('livewire.admin.price-recalc-banner', ['progress' => $progress->current()]);
    }
}
