<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'tron' => [
        'node_url' => env('TRON_NODE_URL', 'https://api.trongrid.io'),
        'api_key' => env('TRON_API_KEY', ''),
        'usdt_contract' => env('TRON_USDT_CONTRACT', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'),
        'encryption_key' => env('TRON_PK_ENC_KEY', ''),
        'hot_wallet_address' => env('TRON_HOT_WALLET_ADDRESS', ''),
        'hot_wallet_private_key' => env('TRON_HOT_WALLET_PRIVATE_KEY', ''),
        'gas_bank_private_key' => env('TRON_GAS_BANK_PRIVATE_KEY', ''),
        'gas_bank_trx_amount' => env('TRON_GAS_BANK_TRX_AMOUNT', 6.0), // Amount of TRX to send from gas bank (should cover gas fee ~5.x TRX per transaction)
        'required_confirmations' => env('TRON_REQUIRED_CONFIRMATIONS', 20),
        'min_sweep_amount' => env('TRON_MIN_SWEEP_AMOUNT', 50.0),
        'min_trx_balance' => env('TRON_MIN_TRX_BALANCE', 1.0),
    ],

];
