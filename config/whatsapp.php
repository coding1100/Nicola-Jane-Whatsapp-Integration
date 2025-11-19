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
            'sub_account_001' => [
                'api_key' => 'pit-4151b93e-f075-440a-aef2-0fd397a0ddf8',
                'location_id' => 'A9OQOsWw1io1F7vu8t5n',
            ],
            'sub_account_002' => [
                'api_key' => 'pit-852f90e1-6983-46a3-ac1b-cb31dbab16e0',
                'location_id' => 'XpIp7h8jzIAHnVkkHvWE',
            ],
            'sub_account_003' => [
                'api_key' => 'pit-edaf6c6b-cb00-425c-a8ae-26e9009583c9',
                'location_id' => 'qMNx5UwNa4l3cQdu1MJn',
            ],
            'sub_account_004' => [
                'api_key' => 'pit-fef01832-b82a-45e1-9db8-d5afd65233a2',
                'location_id' => 'cJVs5PlNLxrPNqJQHVoQ',
            ],
            'sub_account_005' => [
                'api_key' => 'pit-314fb7d4-ec26-452c-a628-297ad4631b86',
                'location_id' => '7PhKbXUjN02tDQuNQ2uP',
            ],
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

    /*
    |--------------------------------------------------------------------------
    | Instance ID to Sub-Account ID Mapping
    |--------------------------------------------------------------------------
    |
    | Map Ultramsg instance IDs to sub-account IDs.
    | This is used when receiving incoming webhooks to determine which
    | sub-account the message belongs to.
    |
    */

    'instance_mappings' => [
        // Map instance ID to sub-account ID
        // Format: 'instance_id' => 'sub_account_id'
        '149866' => 'default',
        'instance149866' => 'default',
        // Add more mappings as needed:
        // 'instance123' => 'sub_account_001',
        // 'instance456' => 'sub_account_002',
    ],

    /*
    |--------------------------------------------------------------------------
    | Location ID to Sub-Account ID Mapping
    |--------------------------------------------------------------------------
    |
    | Map GHL location IDs to sub-account IDs.
    | This is used when receiving GHL webhooks to determine which
    | sub-account the webhook belongs to.
    |
    */

    'location_mappings' => [
        // Map location ID to sub-account ID
        // Format: 'location_id' => 'sub_account_id'
        'qMNx5UwNa4l3cQdu1MJn' => 'default',
        // Add more mappings as needed:
        'A9OQOsWw1io1F7vu8t5n' => 'sub_account_001',
        'XpIp7h8jzIAHnVkkHvWE' => 'sub_account_002',
        'qMNx5UwNa4l3cQdu1MJn' => 'sub_account_003',
        'cJVs5PlNLxrPNqJQHVoQ' => 'sub_account_004',
        '7PhKbXUjN02tDQuNQ2uP' => 'sub_account_005',
    ],
];

