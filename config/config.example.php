<?php
// Copy this file to config.php and fill in your values.
// This file is generated automatically by the installer wizard.

return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'electus',
        'user'    => 'electus_user',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name'          => 'Electus',
        'url'           => 'https://example.com',
        'timezone'      => 'Europe/Rome',
        'debug'         => false,
        'session_name'  => 'electus_session',
        'admin_lang'    => 'en',   // default admin panel language
        'public_lang'   => 'en',   // default public-facing language
    ],
    'mail' => [
        'host'       => 'localhost',
        'port'       => 587,
        'username'   => '',
        'password'   => '',
        'encryption' => 'tls',
        'from_email' => 'noreply@example.com',
        'from_name'  => 'Electus',
    ],
];
