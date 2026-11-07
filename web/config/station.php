<?php

return [
    'offline_seconds' => 20, 'stale_seconds' => 15, 'timeout_seconds' => 30,
    'mqtt' => [
        'host' => env('MQTT_HOST', '127.0.0.1'), 'port' => (int) env('MQTT_PORT', 1883),
        'username' => env('MQTT_USERNAME', 'backend'), 'password' => env('MQTT_PASSWORD'),
        'tls' => (bool) env('MQTT_TLS', false), 'ca_file' => env('MQTT_CA_FILE'),
    ],
];
