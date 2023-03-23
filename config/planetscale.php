<?php

return [
    'organization' => env('PLANETSCALE_ORGANIZATION'),

    'production_branch' => env('PLANETSCALE_PRODUCTION_BRANCH', 'main'),

    'database' => env('DB_DATABASE'),

    /*
     *   For security, when customizing this config,
     *   DO NOT use a hard-coded service token here.
     */
    'service_token' => [
        'id' => env('PLANETSCALE_SERVICE_TOKEN_ID'),
        'value' => env('PLANETSCALE_SERVICE_TOKEN'),
    ],
];
