<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

require_once __DIR__.'/Support/sales.php';
require_once __DIR__.'/Support/storefront_sales.php';
require_once __DIR__.'/Support/seo.php';
require_once __DIR__.'/Support/roles.php';
require_once __DIR__.'/Support/age.php';

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');
