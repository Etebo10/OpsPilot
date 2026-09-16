<?php

declare(strict_types=1);

function csrf_token(): string
{
    if (
        empty(
            $_SESSION['csrf_token']
        )
    ) {

        $_SESSION['csrf_token'] =
            bin2hex(
                random_bytes(32)
            );
    }

    return $_SESSION['csrf_token'];
}


function csrf_field(): string
{
    return sprintf(
        '<input type="hidden" name="csrf_token" value="%s">',
        htmlspecialchars(
            csrf_token(),
            ENT_QUOTES,
            'UTF-8'
        )
    );
}


function verify_csrf(): void
{
    if (
        $_SERVER['REQUEST_METHOD'] !== 'POST'
    ) {
        return;
    }

    $token =
        $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals(
            $_SESSION['csrf_token'],
            $token
        )
    ) {

        http_response_code(419);

        exit(
            'Security verification failed.'
        );
    }
}