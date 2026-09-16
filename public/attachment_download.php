<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

require_auth();
require_permission('inbox.manage');

$user = current_user();
$organizationId = (int) $user['organization_id'];

$messageId = filter_input(INPUT_GET, 'message_id', FILTER_VALIDATE_INT);

if (!$messageId) {
    http_response_code(400);
    exit('Invalid request.');
}

// The organization_id check here is what makes this "secure storage" --
// even if someone guessed a message ID belonging to another business,
// this query simply returns nothing for them.
$stmt = db()->prepare(
    'SELECT attachment_path, attachment_original_name, attachment_mime
     FROM messages
     WHERE id = ? AND organization_id = ? AND attachment_path IS NOT NULL'
);
$stmt->execute([$messageId, $organizationId]);
$message = $stmt->fetch();

if (!$message) {
    http_response_code(404);
    exit('Attachment not found.');
}

$fullPath = __DIR__ . '/../storage/attachments/' . $message['attachment_path'];

// Guard against the stored path ever containing '..' and escaping the
// attachments folder -- defense in depth, even though store_attachment()
// never writes a path like that itself.
$realBase = realpath(__DIR__ . '/../storage/attachments');
$realPath = realpath($fullPath);

if (!$realPath || !$realBase || !str_starts_with($realPath, $realBase)) {
    http_response_code(404);
    exit('Attachment not found.');
}

header('Content-Type: ' . $message['attachment_mime']);
header('Content-Disposition: inline; filename="' . basename($message['attachment_original_name']) . '"');
header('Content-Length: ' . filesize($realPath));
readfile($realPath);
