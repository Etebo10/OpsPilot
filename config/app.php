<?php

declare(strict_types=1);

return [

    'name' => 'OpsPilot',

    'environment' => getenv('APP_ENV') ?: 'local',

    'url' => getenv('APP_URL')
        ?: 'http://localhost/opspilot/public',

    'timezone' => 'Africa/Lagos',

    'session_name' => 'opspilot_session',

    'cookie_secure' =>
        !empty($_SERVER['HTTPS']) &&
        $_SERVER['HTTPS'] !== 'off',

    'cookie_httponly' => true,

    'cookie_samesite' => 'Lax',

];