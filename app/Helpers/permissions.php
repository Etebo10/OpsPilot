<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PERMISSIONS
|--------------------------------------------------------------------------
|
| Plain-English idea: your `users` table already gives every person a
| role (owner, admin, manager, staff). This file is the single place
| that says what each role is *allowed to do*. Every "sensitive action"
| in the app (recording a payment, changing settings, deleting a
| customer, viewing failed automation runs, etc.) should ask
| can($user, 'the_action') before it runs — instead of that decision
| being scattered across dozens of pages.
|
| To add a new permission later: add one line to the map below, then
| call require_permission('that_permission') at the top of the page
| or action that needs protecting.
|
*/

const PERMISSIONS_BY_ROLE = [

    'owner' => [
        // owners can do everything — represented with a wildcard
        '*',
    ],

    'admin' => [
        'customers.manage',
        'jobs.manage',
        'invoices.manage',
        'payments.record',
        'payments.refund',
        'automation.manage',
        'automation.view_failed_runs',
        'inbox.manage',
        'inbox.assign',
        'channels.manage',
        'settings.manage',
        'reports.view',
        'users.invite',
    ],

    'manager' => [
        'customers.manage',
        'jobs.manage',
        'invoices.manage',
        'payments.record',
        'inbox.manage',
        'inbox.assign',
        'reports.view',
    ],

    'staff' => [
        'customers.view',
        'jobs.manage_own',
        'invoices.view',
        'inbox.manage',
        'reports.view',
    ],
];


/**
 * Does this user's role allow this action?
 *
 * @param array<string,mixed>|null $user  the array returned by current_user()
 * @param string $permission               e.g. 'payments.refund'
 */
function can(?array $user, string $permission): bool
{
    if (!$user || empty($user['role'])) {
        return false;
    }

    $allowed = PERMISSIONS_BY_ROLE[$user['role']] ?? [];

    return in_array('*', $allowed, true)
        || in_array($permission, $allowed, true);
}


/**
 * Stop the page dead if the logged-in user cannot do this.
 * Call this at the very top of any page/action that performs
 * a sensitive operation, right after require_auth().
 */
function require_permission(string $permission): void
{
    $user = current_user();

    if (!can($user, $permission)) {

        log_action(
            action: 'permission_denied',
            entityType: 'permission',
            entityId: null,
            metadata: ['permission' => $permission]
        );

        http_response_code(403);

        exit('You do not have permission to do that.');
    }
}
