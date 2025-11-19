<?php

return [
    /*
    |--------------------------------------------------------------------------
    | GoHighLevel (GHL) Credentials
    |--------------------------------------------------------------------------
    |
    | Configure your GHL API key and location ID here.
    | You can set default values or per-sub-account values.
    |
    */

    'ghl' => [
        // Default GHL API Key (used if sub-account specific key not found)
        'api_key' => 'pit-edaf6c6b-cb00-425c-a8ae-26e9009583c9',

        // Default GHL Location ID (used if sub-account specific location not found)
        'location_id' => 'qMNx5UwNa4l3cQdu1MJn',

        // Per sub-account GHL credentials
        // Add your sub-accounts here:
        'sub_accounts' => [
            // Example:
            // 'sub_account_001' => [
            //     'api_key' => 'your_ghl_api_key_here',
            //     'location_id' => 'your_location_id_here',
            // ],
            // 'sub_account_002' => [
            //     'api_key' => 'another_api_key',
            //     'location_id' => 'another_location_id',
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ultramsg Default Credentials (Optional)
    |--------------------------------------------------------------------------
    |
    | These are fallback credentials if no sub-account specific credentials
    | are found in the database. Ultramsg credentials are primarily stored
    | in the database via the /onboard endpoint.
    |
    */

    'ultramsg' => [
        // Default Ultramsg Instance ID (used if sub-account specific credentials not found)
        'instance_id' => 'instance149866',

        // Default Ultramsg API Token (used if sub-account specific credentials not found)
        'api_token' => 'ro37j993kbiptecy',

        // Per sub-account Ultramsg credentials (optional)
        // Add your sub-accounts here if you have multiple:
        'sub_accounts' => [
            // Example:
            // 'default' => [
            //     'instance_id' => 'instance123',
            //     'api_token' => 'token123',
            // ],
            // 'sub_account_001' => [
            //     'instance_id' => 'instance456',
            //     'api_token' => 'token456',
            // ],
        ],
    ],
];

