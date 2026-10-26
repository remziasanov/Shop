<?php

declare(strict_types=1);

// D-044: the Livewire temporary upload limit is 50 MB for every component, so each upload field must set its own.
it('gives every Filament file upload field an explicit maximum size', function (): void {
    $missing = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament'), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        $fields = preg_match_all('/FileUpload::make\(/', $source);

        if ($fields > 0 && preg_match_all('/->maxSize\(/', $source) < $fields) {
            $missing[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    expect($missing)->toBe([]);
});
