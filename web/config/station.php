<?php

return [
    'offline_seconds' => 20, 'stale_seconds' => 15, 'timeout_seconds' => 30,
    // Sensor berada di wadah: perintah ditolak saat wadah sudah penuh, kenaikan isi mengonfirmasi penyaluran.
    'bowl_food_full_g' => (float) env('BOWL_FOOD_FULL_G', 200), 'bowl_water_full_pct' => (float) env('BOWL_WATER_FULL_PCT', 90),
    'min_food_increase_g' => 0.5, 'min_water_increase_pct' => 1,
    'mqtt' => [
        'host' => env('MQTT_HOST', '127.0.0.1'), 'port' => (int) env('MQTT_PORT', 1883),
        'username' => env('MQTT_USERNAME', 'backend'), 'password' => env('MQTT_PASSWORD'),
        'tls' => (bool) env('MQTT_TLS', false), 'ca_file' => env('MQTT_CA_FILE'),
    ],
];
