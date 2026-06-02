<?php

return [
    'wordpress' => [
        'fr_url'   => env('WORDPRESS_FR_URL', 'https://preprod.aeromorning.com'),
        'en_url'   => env('WORDPRESS_EN_URL', 'https://preprod.aeromorning.com/en'),
        'auth_fr'  => env('WORDPRESS_AUTH_FR', ''),
        'auth_en'  => env('WORDPRESS_AUTH_EN', ''),
        'yoast_auth_fr' => env('WORDPRESS_YOAST_AUTH_FR', env('WORDPRESS_AUTH_FR', '')),
        'yoast_auth_en' => env('WORDPRESS_YOAST_AUTH_EN', env('WORDPRESS_AUTH_EN', '')),
        'allow_title_fallback' => env('WORDPRESS_ALLOW_TITLE_FALLBACK', false),
    ],

    'notify' => [
        'email' => env('NOTIFY_EMAIL', 'rado.rakotoarivelo@amws.space'),
    ],

    'imap' => [
        'host' => env('IMAP_HOST'),
        'port' => env('IMAP_PORT', 993),
        'username' => env('IMAP_USERNAME'),
        'password' => env('IMAP_PASSWORD'),
        'encryption' => env('IMAP_ENCRYPTION', 'ssl'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4-turbo-preview'),
    ],

    'image' => [
        'max_size' => env('IMAGE_MAX_SIZE', 1000000),
        'width' => env('IMAGE_WIDTH', 700),
        'height' => env('IMAGE_HEIGHT', 400),
        'background_color' => env('IMAGE_BACKGROUND_COLOR', '#005A8C'),
        'format' => env('IMAGE_FORMAT', 'jpg'),
        'quality' => env('IMAGE_QUALITY', 85),
    ],
];
