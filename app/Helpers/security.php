<?php

declare(strict_types=1);


/*
|--------------------------------------------------------------------------
| Escape HTML
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| Generate secure random token
|--------------------------------------------------------------------------
*/

function randomToken(int $bytes = 32): string
{
    return bin2hex(
        random_bytes($bytes)
    );
}


/*
|--------------------------------------------------------------------------
| Basic request method check
|--------------------------------------------------------------------------
*/

function requirePost(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

        http_response_code(405);

        header(
            'Allow: POST'
        );

        exit('Method Not Allowed');
    }
}


/*
|--------------------------------------------------------------------------
| Redirect
|--------------------------------------------------------------------------
*/

function redirect(string $url): never
{
    header(
        'Location: ' . $url
    );

    exit;
}


function redirect_to(string $url): never
{
    redirect($url);
}


function base_url(string $path = ''): string
{
    $base = rtrim(
        getenv('APP_URL') ?: 'http://localhost/opspilot/public',
        '/'
    );

    return $base . '/' . ltrim($path, '/');
}


/*
|--------------------------------------------------------------------------
| Generate URL-friendly slug
|--------------------------------------------------------------------------
*/

function slugify(string $value): string
{
    $value = trim(
        strtolower($value)
    );

    $value = preg_replace(
        '/[^a-z0-9]+/',
        '-',
        $value
    );

    return trim(
        $value,
        '-'
    );
}


/*
|--------------------------------------------------------------------------
| Ensure unique organization slug
|--------------------------------------------------------------------------
*/

function uniqueOrganizationSlug(
    string $name
): string {

    $base = slugify($name);

    if ($base === '') {
        $base = 'business';
    }

    $slug = $base;

    $counter = 1;

    $pdo = db();

    while (true) {

        $stmt = $pdo->prepare(
            'SELECT id
             FROM organizations
             WHERE slug = ?
             LIMIT 1'
        );

        $stmt->execute([
            $slug
        ]);

        if (!$stmt->fetch()) {
            return $slug;
        }

        $counter++;

        $slug =
            $base . '-' . $counter;
    }
}