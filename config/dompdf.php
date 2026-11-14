<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PDF generation (barryvdh/laravel-dompdf, D-079, TZ 18.2)
|--------------------------------------------------------------------------
| Locked down: no remote resources, no PHP or JavaScript inside templates, local files only from the template
| directory (chroot); the logo and the signature image are embedded as data URIs by OrderPdfRenderer. Fonts: the
| DejaVu family shipped with dompdf (Cyrillic and «₽»), the font cache lives in storage/fonts (writable on Beget).
*/
return [
    'show_warnings' => false,
    'public_path' => null,
    'convert_entities' => false,

    'options' => [
        'font_dir' => storage_path('fonts'),
        'font_cache' => storage_path('fonts'),
        // Under storage/app/private/tmp: `shop:cleanup` removes files older than a day from there.
        'temp_dir' => storage_path('app/private/tmp/dompdf'),
        'chroot' => [(string) realpath(resource_path('views/pdf'))],
        'allowed_protocols' => [
            'data://' => ['rules' => []],
            'file://' => ['rules' => []],
        ],
        'artifactPathValidation' => null,
        'log_output_file' => null,
        // Subsetting keeps a file with a few fonts in the tens of kilobytes instead of ~700 KB per font face.
        'enable_font_subsetting' => true,
        'pdf_backend' => 'CPDF',
        'default_media_type' => 'print',
        'default_paper_size' => 'a4',
        'default_paper_orientation' => 'portrait',
        'default_font' => 'DejaVu Sans',
        'dpi' => 96,
        'enable_php' => false,
        'enable_javascript' => false,
        'enable_remote' => false,
        'allowed_remote_hosts' => [],
        'font_height_ratio' => 1.1,
        'enable_html5_parser' => true,
    ],
];
