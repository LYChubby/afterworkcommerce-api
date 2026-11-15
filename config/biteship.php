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

    'timeout' => 20,
];
