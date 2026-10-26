<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Home\HomePageRenderer;
use Illuminate\Contracts\View\View;

class HomeController extends Controller
{
    public function __invoke(HomePageRenderer $renderer): View
    {
        return view('storefront.home', ['blocksHtml' => $renderer->render()]);
    }
}
