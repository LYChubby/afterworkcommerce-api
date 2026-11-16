<?php

return [
    'api_key' => env('BITESHIP_API_KEY'),

    'base_url' => env(
        'BITESHIP_BASE_URL',
        'https://api.biteship.com/v1'
    ),

    'couriers' => array_values(array_filter(
        array_map(
            'trim',
            explode(',', env('BITESHIP_COURIERS', ''))
        )
    )),

    'webhook_header' => env(
        'BITESHIP_WEBHOOK_HEADER',
        'X-AfterWork-Biteship-Key'
    ),

    'webhook_secret' => env(
        'BITESHIP_WEBHOOK_SECRET'
    ),

    'timeout' => 20,
];
