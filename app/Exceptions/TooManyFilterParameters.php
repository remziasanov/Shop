<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Services\Catalog\AttributeFilterParser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * More than {@see AttributeFilterParser::MAX_FILTERS} characteristic filters in one request:
 * answered with 404 (TZ 20.7), a guard against bots walking the combinations.
 */
final class TooManyFilterParameters extends NotFoundHttpException
{
    public function __construct()
    {
        parent::__construct('Too many filter parameters.');
    }
}
