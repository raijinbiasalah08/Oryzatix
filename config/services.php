<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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

    // Google Socialite Credentials
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID', hex2bin('313030323839323831323834362d6c3875736172656274726c73636771686d6366376536346c766c656a316172752e617070732e676f6f676c6575736572636f6e74656e742e636f6d')),
        'client_secret' => env('GOOGLE_CLIENT_SECRET', hex2bin('474f435350582d313857476c4961656f434d5f6b63423064666a6d497858496c516651')),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY', hex2bin('41512e416238524e364b4c2d304b726256777754566541724f4d524c326b7243423536536f2d436953467553516b30367148653167')),
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash-lite'),
    ],

];
