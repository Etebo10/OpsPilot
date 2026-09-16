<?php

declare(strict_types=1);


/*
|--------------------------------------------------------------------------
| Error reporting
|--------------------------------------------------------------------------
*/

if (
    getenv('APP_ENV') === 'production'
) {

    ini_set(
        'display_errors',
        '0'
    );

    ini_set(
        'log_errors',
        '1'
    );

} else {

    ini_set(
        'display_errors',
        '1'
    );

    error_reporting(
        E_ALL
    );
}


/*
|--------------------------------------------------------------------------
| Load environment variables
|--------------------------------------------------------------------------
*/

function loadEnv(
    string $file
): void {

    if (
        !file_exists($file)
    ) {
        return;
    }

    $lines =
        file(
            $file,
            FILE_IGNORE_NEW_LINES |
            FILE_SKIP_EMPTY_LINES
        );

    foreach ($lines as $line) {

        $line =
            trim($line);

        if (
            $line === '' ||
            str_starts_with(
                $line,
                '#'
            )
        ) {
            continue;
        }

        if (
            !str_contains(
                $line,
                '='
            )
        ) {
            continue;
        }

        [
            $key,
            $value
        ] =
            explode(
                '=',
                $line,
                2
            );

        $key =
            trim($key);

        $value =
            trim($value);

        if (
            (
                str_starts_with(
                    $value,
                    '"'
                )
                &&
                str_ends_with(
                    $value,
                    '"'
                )
            )
            ||
            (
                str_starts_with(
                    $value,
                    "'"
                )
                &&
                str_ends_with(
                    $value,
                    "'"
                )
            )
        ) {

            $value =
                substr(
                    $value,
                    1,
                    -1
                );
        }

        if (
            getenv($key) === false
        ) {

            putenv(
                $key . '=' . $value
            );
        }
    }
}


loadEnv(
    dirname(__DIR__) . '/.env'
);


/*
|--------------------------------------------------------------------------
| Timezone
|--------------------------------------------------------------------------
*/

date_default_timezone_set(
    getenv('APP_TIMEZONE')
    ?: 'Africa/Lagos'
);


/*
|--------------------------------------------------------------------------
| Core dependencies
|--------------------------------------------------------------------------
*/

require_once
    __DIR__ .
    '/../config/database.php';

require_once
    __DIR__ .
    '/Helpers/security.php';

require_once
    __DIR__ .
    '/Helpers/auth.php';

require_once
    __DIR__ .
    '/Helpers/csrf.php';

require_once
    __DIR__ .
    '/Helpers/permissions.php';

require_once
    __DIR__ .
    '/Helpers/audit.php';

require_once
    __DIR__ .
    '/Helpers/automation.php';

require_once
    __DIR__ .
    '/Helpers/inbox.php';

require_once
    __DIR__ .
    '/Helpers/widget.php';

require_once
    __DIR__ .
    '/Helpers/invoices.php';

require_once
    __DIR__ .
    '/Helpers/crypto.php';

require_once
    __DIR__ .
    '/Helpers/paystack.php';

require_once
    __DIR__ .
    '/Helpers/email.php';

require_once
    __DIR__ .
    '/Helpers/telegram.php';

require_once
    __DIR__ .
    '/Helpers/response.php';

require_once
    __DIR__ .
    '/core/helpers.php';

$appConfig = require __DIR__ . '/../config/app.php';


/*
|--------------------------------------------------------------------------
| Session
|--------------------------------------------------------------------------
*/

if (!function_exists('startAppSession')) {
    function startAppSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $config = $GLOBALS['appConfig'] ?? [];

            session_name(
                $config['session_name'] ?? 'opspilot_session'
            );

            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => (bool) ($config['cookie_secure'] ?? false),
                'httponly' => (bool) ($config['cookie_httponly'] ?? true),
                'samesite' => $config['cookie_samesite'] ?? 'Lax'
            ]);

            session_start();
        }
    }
}

$GLOBALS['appConfig'] = $appConfig;

startAppSession();


/*
|--------------------------------------------------------------------------
| Security headers
|--------------------------------------------------------------------------
*/

header(
    'X-Content-Type-Options: nosniff'
);

header(
    'X-Frame-Options: SAMEORIGIN'
);

header(
    'Referrer-Policy: strict-origin-when-cross-origin'
);

header(
    'Permissions-Policy: geolocation=(), microphone=(), camera=()'
);

header(
    'X-XSS-Protection: 0'
);