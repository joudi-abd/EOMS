<?php

return [
    'adms' => [
        'enabled' => (bool) env('ATTENDANCE_ADMS_ENABLED', false),
        // Only enable behind an isolated LAN/VPN with a firewall restricted to devices.
        'allow_http' => (bool) env('ATTENDANCE_ADMS_ALLOW_HTTP', false),
        'remote_enrollment' => (bool) env('ATTENDANCE_ADMS_REMOTE_ENROLLMENT', false),
        'max_body_bytes' => 1048576,
        'max_records' => 5000,
        'command_timeout_minutes' => 5,
    ],
];
