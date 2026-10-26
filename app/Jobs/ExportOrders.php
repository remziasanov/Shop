<?php

declare(strict_types=1);

namespace App\Jobs;

/**
 * Builds a large request export (more than `shop.export_sync_limit` rows) in the background (EX-03).
 * Same behaviour as the catalog export job: the file is written in one pass, chunks of 200 orders.
 */
class ExportOrders extends ExportCatalog {}
