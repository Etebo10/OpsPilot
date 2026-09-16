<?php

declare(strict_types=1);

function loginUser(
    int $userId,
    int $organizationId,
    string $role
): void
{
    session_regenerate_id(true);

    $_SESSION['user_id'] = $userId;
    $_SESSION['organization_id'] = $organizationId;
    $_SESSION['role'] = $role;
    $_SESSION['authenticated_at'] = time();
    $_SESSION['last_activity'] = time();

    unset($_SESSION['csrf_token']);
}


function logoutUser(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool) $params['secure'],
            (bool) $params['httponly']
        );
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}


function isAuthenticated(): bool
{
    return isset(
        $_SESSION['user_id'],
        $_SESSION['organization_id']
    )
        && (int) $_SESSION['user_id'] > 0
        && (int) $_SESSION['organization_id'] > 0;
}


function requireAuth(): void
{
    if (!isAuthenticated()) {
        redirect('login.php?expired=1');
    }

    $timeout = 60 * 60 * 8;

    if (
        isset($_SESSION['last_activity']) &&
        time() - (int) $_SESSION['last_activity'] > $timeout
    ) {
        logoutUser();
        redirect('login.php?expired=1');
    }

    // Hardening: if the user's account or organization was deactivated
    // *after* they logged in, kick them out on the very next request
    // instead of waiting for their session to expire naturally.
    if (!currentUser() || !currentOrganization()) {
        logoutUser();
        redirect('login.php?expired=1');
    }

    $_SESSION['last_activity'] = time();
}


function currentUser(): ?array
{
    static $user = false;

    if ($user !== false) {
        return $user;
    }

    if (!isAuthenticated()) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT id, organization_id, name, email, role, is_active
         FROM users
         WHERE id = ? AND organization_id = ?
         LIMIT 1'
    );

    $stmt->execute([
        (int) $_SESSION['user_id'],
        (int) $_SESSION['organization_id']
    ]);

    $user = $stmt->fetch() ?: null;

    if (!$user || !(bool) $user['is_active']) {
        $user = null;
        logoutUser();
    }

    return $user;
}


function currentOrganizationId(): ?int
{
    if (!isAuthenticated()) {
        return null;
    }

    return (int) $_SESSION['organization_id'];
}


function currentOrganization(): ?array
{
    static $organization = false;

    if ($organization !== false) {
        return $organization;
    }

    $organizationId = currentOrganizationId();

    if ($organizationId === null) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT *
         FROM organizations
         WHERE id = ? AND is_active = TRUE
         LIMIT 1'
    );

    $stmt->execute([$organizationId]);

    $organization = $stmt->fetch() ?: null;

    return $organization;
}


function require_auth(): void
{
    requireAuth();
}


function current_user(): ?array
{
    return currentUser();
}