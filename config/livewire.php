<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Temporary file uploads
    |--------------------------------------------------------------------------
    | Livewire's own limit for any upload is 12 MB by default; import files (IM-01) may be up to 50 MB.
    | Each upload field still enforces its own, smaller limit (photos: 10 MB, import: ImportWizard::maxUploadBytes()).
    | The rule is global (Livewire cannot set it per component), see D-044. Files go to the default (private) disk.
    */
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
        'rules' => ['required', 'file', 'max:51200'],
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],

];
