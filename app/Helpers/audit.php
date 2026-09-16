<?php

declare(strict_types=1);


function log_action(
    string $action,
    string $entityType,
    ?int $entityId = null,
    ?array $before = null,
    ?array $after = null,
    array $metadata = []
): void {

    try {
        $organizationId = currentOrganizationId();
        $user = isAuthenticated() ? currentUser() : null;

        $stmt = db()->prepare(
            'INSERT INTO audit_logs
                (organization_id, actor_user_id, action, entity_type,
                 entity_id, before_data, after_data, metadata, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );

        $stmt->execute([
            $organizationId,
            $user['id'] ?? null,
            $action,
            $entityType,
            $entityId,
            $before !== null ? json_encode($before) : null,
            $after !== null ? json_encode($after) : null,
            !empty($metadata) ? json_encode($metadata) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

    } catch (Throwable $e) {
        // The audit log must NEVER be the reason a real action fails.
        // If writing the log itself breaks, we record it to the normal
        // PHP error log instead of throwing.
        error_log('audit_log write failed: ' . $e->getMessage());
    }
}
