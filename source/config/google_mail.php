<?php
return [
    'client_id' => env('GOOGLE_MAIL_CLIENT_ID'),
    'client_secret' => env('GOOGLE_MAIL_CLIENT_SECRET'),
    'daily_limit_max' => (int) env('GOOGLE_MAIL_DAILY_LIMIT_MAX', 500),
];
