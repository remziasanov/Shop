<?php

declare(strict_types=1);

namespace Tests;

use App\Services\Catalog\CatalogVersion;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The catalog version is memoized in a static: every test starts without the previous test's token.
        CatalogVersion::reset();
    }
}
