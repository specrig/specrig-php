<?php

return [
    'crm' => [
        'url' => env('CRM_URL'),
        'timeout' => env('CRM_TIMEOUT', 5),
    ],
    'rabbit' => env('RABBIT_URL', 'amqp://localhost'),
];
