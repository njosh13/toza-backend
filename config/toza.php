<?php

declare(strict_types=1);

return [

    // Every legal deadline is counted in Nairobi calendar days, in one place:
    // App\Support\OrderCalendar. app.timezone stays UTC.
    'timezone' => env('TOZA_TIMEZONE', 'Africa/Nairobi'),

    // Origins allowed by CORS (with credentials), comma-separated.
    'frontend_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FRONTEND_ORIGINS', 'http://localhost:3000')),
    ))),

    // Seed demo firm/users/matters in production too (staging only).
    'demo_seed' => (bool) env('DEMO_SEED', false),

];