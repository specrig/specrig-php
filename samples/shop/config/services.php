<?php

return [

    'billing' => [
        'url' => env('BILLING_URL', 'https://billing.example.com'),
    ],

    'rabbit' => [
        'url' => env('RABBIT_URL', 'amqp://guest:guest@localhost:5672/'),
    ],

];
