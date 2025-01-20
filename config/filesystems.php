<?php

return [

    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
        ],

        'files' => [
            'driver' => 'local',
            'root' => storage_path('files'),
            'url' => env('APP_URL').'/storage/files',
            'visibility' => 'public',
        ],
    ],

];
