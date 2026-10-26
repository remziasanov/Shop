<?php

declare(strict_types=1);

namespace App\Jobs;

/**
 * Follow-up of {@see ProcessImportChunk} once every chunk is applied: works through the photo tasks that did not
 * fit into the time budget of their chunk and finishes the import.
 */
class ProcessImportPhotos extends ProcessesImportSteps {}
