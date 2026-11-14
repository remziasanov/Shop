<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Pwa\PwaService;
use Illuminate\Http\JsonResponse;

/**
 * MB-20: GET /manifest.webmanifest. 404 while the installable app is off.
 */
class ManifestController extends Controller
{
    public function __invoke(PwaService $pwa): JsonResponse
    {
        $manifest = $pwa->manifest();

        abort_if($manifest === null, 404);

        return response()->json($manifest, 200, [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'public, max-age=300',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
