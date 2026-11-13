<?php

declare(strict_types=1);

return [
    'secret_key' => env('CHAPA_SECRET_KEY'),
    'public_key' => env('CHAPA_PUBLIC_KEY'),
    'webhook_secret' => env('CHAPA_WEBHOOK_SECRET'),
    'base_url' => env('CHAPA_BASE_URL', 'https://api.chapa.global'),
    'timeout' => (int) env('CHAPA_TIMEOUT', 15),
];
