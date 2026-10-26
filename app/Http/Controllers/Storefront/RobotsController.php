<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Seo\RobotsBuilder;
use Illuminate\Http\Response;

class RobotsController extends Controller
{
    public function __invoke(RobotsBuilder $robots): Response
    {
        return response($robots->build(), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
