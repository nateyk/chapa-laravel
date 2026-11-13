<?php

declare(strict_types=1);

return [
    'secret_key' => env('CHAPA_SECRET_KEY'),
    'public_key' => env('CHAPA_PUBLIC_KEY'),
    'webhook_secret' => env('CHAPA_WEBHOOK_SECRET'),
    'base_url' => env('CHAPA_BASE_URL', 'https://api.chapa.global'),
    'timeout' => (int) env('CHAPA_TIMEOUT', 15),
    'webhook_inbox' => [
        'mode' => env('CHAPA_MODE', 'test'),
        'integration' => env('CHAPA_WEBHOOK_INTEGRATION', 'default'),
        'max_bytes' => (int) env('CHAPA_WEBHOOK_MAX_BYTES', 131072),
        'max_attempts' => (int) env('CHAPA_WEBHOOK_MAX_ATTEMPTS', 8),
    ],
];
