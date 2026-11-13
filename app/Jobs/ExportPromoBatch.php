<?php

declare(strict_types=1);

namespace App\Jobs;

/**
 * Builds the export of a large promo code batch in the background (PM-16, EX-03); same behaviour as the catalog export job.
 */
class ExportPromoBatch extends ExportCatalog {}
