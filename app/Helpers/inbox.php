<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| INBOX
|--------------------------------------------------------------------------
|
| Plain-English map of how this connects to what you already have:
|
|   - receive_inbound_message() is the ONE function every future channel
|     (website chat widget, inbound email, WhatsApp webhook, Instagram
|     webhook) will call when a customer message arrives. Building it
|     once, generically, now means Phases 6-9 are mostly just "receive
|     the provider's webhook, translate it into plain fields, call this
|     function" -- not reinventing the inbox each time.
|
|   - send_message() is what runs when STAFF reply. It doesn't send
|     anything itself -- it writes the message, then hands the actual
|     sending off to the same durable job queue from Phase 4
|     (enqueue_job / claim_next_job / retry with backoff / dead_letter).
|     That's what makes "failed messages retry safely without
|     duplication" true here, for free, because that machinery already
|     exists.
|
*/


/**
 * For channels that identify people by an internal ID rather than
 * email/phone (Telegram's chat_id, Instagram's PSID later). Matches
 * an existing contact by that ID, or creates a new one and remembers
 * it in contacts.channel_identities for next time.
 */
function find_or_create_contact_by_channel_identity(
    int $organizationId,
    string $channelType,
    string $externalId,
    ?string $name
): array {
    $stmt = db()->prepare(
        'SELECT * FROM contacts
         WHERE organization_id = ? AND JSON_UNQUOTE(JSON_EXTRACT(channel_identities, ?)) = ?
         LIMIT 1'
    );
    $stmt->execute([$organizationId, '$.' . $channelType, $externalId]);

    if ($existing = $stmt->fetch()) {
        return $existing;
    }

    $insert = db()->prepare(
        'INSERT INTO contacts (organization_id, name, channel_identities)
         VALUES (?, ?, JSON_OBJECT(?, ?))'
    );
    $insert->execute([$organizationId, $name, $channelType, $externalId]);

    $id = (int) db()->lastInsertId();

    $fetch = db()->prepare('SELECT * FROM contacts WHERE id = ?');
    $fetch->execute([$id]);
    return $fetch->fetch();
}


/**
 * Find a contact by email or phone within this organization, or
 * create a new one. If the email/phone matches an existing customer,
 * link them -- so a message from a known customer shows their name
 * and history instead of being a stranger.
 */
function find_or_create_contact(int $organizationId, ?string $name, ?string $email, ?string $phone): array
{
    $email = $email ? strtolower(trim($email)) : null;
    $phone = $phone ? trim($phone) : null;

    if ($email) {
        $stmt = db()->prepare(
            'SELECT * FROM contacts WHERE organization_id = ? AND email = ? LIMIT 1'
        );
        $stmt->execute([$organizationId, $email]);
        if ($existing = $stmt->fetch()) {
            return $existing;
        }
    }

    if ($phone) {
        $stmt = db()->prepare(
            'SELECT * FROM contacts WHERE organization_id = ? AND phone = ? LIMIT 1'
        );
        $stmt->execute([$organizationId, $phone]);
        if ($existing = $stmt->fetch()) {
            return $existing;
        }
    }

    // Try to match an existing customer by email or phone, so the
    // inbox links straight to someone's existing job/invoice history.
    $customerId = null;
    if ($email || $phone) {
        $match = db()->prepare(
            'SELECT id FROM customers
             WHERE organization_id = ?
               AND ((email = ? AND ? IS NOT NULL) OR (phone = ? AND ? IS NOT NULL))
             LIMIT 1'
        );
        $match->execute([$organizationId, $email, $email, $phone, $phone]);
        $customerId = $match->fetchColumn() ?: null;
    }

    $stmt = db()->prepare(
        'INSERT INTO contacts (organization_id, customer_id, name, email, phone)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$organizationId, $customerId, $name, $email, $phone]);

    $id = (int) db()->lastInsertId();

    $fetch = db()->prepare('SELECT * FROM contacts WHERE id = ?');
    $fetch->execute([$id]);
    return $fetch->fetch();
}


/**
 * The function every channel adapter calls when a new message
 * arrives from a customer. Finds (or starts) the right conversation,
 * records the message, reopens the thread if it had been resolved,
 * and tells the automation system (Phase 4) so rules like "notify
 * staff on new inbox message" can fire.
 */
function receive_inbound_message(
    int $organizationId,
    string $channelType,
    ?int $channelAccountId,
    ?string $contactName,
    ?string $contactEmail,
    ?string $contactPhone,
    string $body,
    ?array $attachment = null,
    ?string $externalThreadId = null,
    ?string $subject = null
): array {
    $contact = find_or_create_contact($organizationId, $contactName, $contactEmail, $contactPhone);

    return deliver_inbound_message_to_contact(
        $organizationId, $contact, $channelType, $channelAccountId, $body, $attachment, $externalThreadId, $subject
    );
}


/**
 * Same idea as receive_inbound_message_for_known_contact(), but for
 * channels (Telegram, Instagram) that identify the sender by their
 * own internal ID rather than a name/email/phone we're told upfront.
 */
function receive_inbound_message_by_channel_identity(
    int $organizationId,
    string $channelType,
    ?int $channelAccountId,
    string $externalId,
    ?string $name,
    string $body,
    ?string $externalThreadId = null
): array {
    $contact = find_or_create_contact_by_channel_identity($organizationId, $channelType, $externalId, $name);

    return deliver_inbound_message_to_contact(
        $organizationId, $contact, $channelType, $channelAccountId, $body, null, $externalThreadId
    );
}


/**
 * Same as receive_inbound_message(), but for callers that already
 * know exactly which contact this is (e.g. the website widget, which
 * resolves the contact from a signed visitor token instead of by
 * matching a name/email/phone every time).
 */
function receive_inbound_message_for_known_contact(
    int $organizationId,
    int $contactId,
    string $channelType,
    ?int $channelAccountId,
    string $body,
    ?array $attachment = null
): array {
    $stmt = db()->prepare('SELECT * FROM contacts WHERE id = ? AND organization_id = ?');
    $stmt->execute([$contactId, $organizationId]);
    $contact = $stmt->fetch();

    if (!$contact) {
        throw new RuntimeException('Unknown contact.');
    }

    return deliver_inbound_message_to_contact($organizationId, $contact, $channelType, $channelAccountId, $body, $attachment);
}


function deliver_inbound_message_to_contact(
    int $organizationId,
    array $contact,
    string $channelType,
    ?int $channelAccountId,
    string $body,
    ?array $attachment = null,
    ?string $externalThreadId = null,
    ?string $subject = null
): array {
    // Find an existing conversation with this contact on this channel
    // that isn't archived -- otherwise start a fresh one.
    $stmt = db()->prepare(
        'SELECT * FROM conversations
         WHERE organization_id = ? AND contact_id = ? AND channel_type = ? AND status != \'archived\'
         ORDER BY last_message_at DESC LIMIT 1'
    );
    $stmt->execute([$organizationId, $contact['id'], $channelType]);
    $conversation = $stmt->fetch();

    if (!$conversation) {
        $publicId = uuid();

        $insert = db()->prepare(
            'INSERT INTO conversations
                (public_id, organization_id, contact_id, channel_account_id, channel_type,
                 external_thread_id, subject, status, last_message_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'open\', NOW())'
        );
        $insert->execute([
            $publicId, $organizationId, $contact['id'], $channelAccountId,
            $channelType, $externalThreadId, $subject,
        ]);

        $conversationId = (int) db()->lastInsertId();
    } else {
        $conversationId = (int) $conversation['id'];

        // A reply from the customer on a resolved thread means it's
        // not actually resolved anymore.
        $reopenStatus = in_array($conversation['status'], ['resolved', 'archived'], true) ? 'open' : $conversation['status'];

        db()->prepare(
            'UPDATE conversations
             SET status = ?, unread_count = unread_count + 1, last_message_at = NOW()
             WHERE id = ?'
        )->execute([$reopenStatus, $conversationId]);
    }

    if (!$conversation) {
        db()->prepare(
            'UPDATE conversations SET unread_count = unread_count + 1 WHERE id = ?'
        )->execute([$conversationId]);
    }

    $attachmentData = $attachment ? store_attachment($organizationId, $attachment) : null;

    $messagePublicId = uuid();

    $stmt = db()->prepare(
        'INSERT INTO messages
            (public_id, conversation_id, organization_id, direction, sender_type,
             body, attachment_path, attachment_original_name, attachment_mime,
             attachment_size_bytes, status)
         VALUES (?, ?, ?, \'inbound\', \'contact\', ?, ?, ?, ?, ?, \'delivered\')'
    );
    $stmt->execute([
        $messagePublicId, $conversationId, $organizationId, $body,
        $attachmentData['path'] ?? null,
        $attachmentData['original_name'] ?? null,
        $attachmentData['mime'] ?? null,
        $attachmentData['size'] ?? null,
    ]);

    $messageId = (int) db()->lastInsertId();

    emit_event($organizationId, 'inbox.new_message', $conversationId, [
        'contact_name' => $contact['name'] ?: ($contact['email'] ?: $contact['phone']),
    ]);

    return ['conversation_id' => $conversationId, 'message_id' => $messageId];
}


/**
 * Staff replying, or leaving an internal note. $isInternalNote = true
 * means it's visible in the thread to staff only and is never queued
 * for actual sending.
 */
function send_message(
    int $organizationId,
    int $conversationId,
    int $userId,
    string $body,
    ?array $attachment = null,
    bool $isInternalNote = false
): array {

    $conversation = get_conversation($organizationId, $conversationId);
    if (!$conversation) {
        throw new RuntimeException('Conversation not found.');
    }

    $attachmentData = $attachment ? store_attachment($organizationId, $attachment) : null;

    $direction = $isInternalNote ? 'internal' : 'outbound';
    $status = $isInternalNote ? 'delivered' : 'queued';
    $messagePublicId = uuid();

    $stmt = db()->prepare(
        'INSERT INTO messages
            (public_id, conversation_id, organization_id, direction, sender_type, sender_user_id,
             body, attachment_path, attachment_original_name, attachment_mime,
             attachment_size_bytes, status)
         VALUES (?, ?, ?, ?, \'staff\', ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $messagePublicId, $conversationId, $organizationId, $direction, $userId,
        $body,
        $attachmentData['path'] ?? null,
        $attachmentData['original_name'] ?? null,
        $attachmentData['mime'] ?? null,
        $attachmentData['size'] ?? null,
        $status,
    ]);

    $messageId = (int) db()->lastInsertId();

    db()->prepare(
        'UPDATE conversations SET last_message_at = NOW() WHERE id = ? AND organization_id = ?'
    )->execute([$conversationId, $organizationId]);

    if (!$isInternalNote) {
        // Hand the actual sending off to the Phase 4 job queue -- same
        // retry/backoff/dead-letter machinery as everything else.
        enqueue_job(
            organizationId: $organizationId,
            jobType: 'send_message',
            payload: ['message_id' => $messageId, 'conversation_id' => $conversationId],
            idempotencyKey: 'send_message:' . $messageId
        );
    }

    log_action(
        action: $isInternalNote ? 'inbox.note_added' : 'inbox.reply_sent',
        entityType: 'conversation',
        entityId: $conversationId
    );

    return ['message_id' => $messageId];
}


/**
 * The action Phase 4's run_action() dispatches to for job_type
 * 'send_message'. Only 'website_chat' actually "delivers" today
 * (delivering just means the message becomes visible to the visitor
 * via the widget, built in Phase 6 -- no external API call needed).
 * Every other channel throws on purpose until its adapter phase
 * builds a real sender -- same honest pattern as Phase 4.
 */
function action_send_message(int $organizationId, array $payload): string
{
    $messageId = (int) $payload['message_id'];

    $stmt = db()->prepare(
        'SELECT m.*, c.channel_type
         FROM messages m
         INNER JOIN conversations c ON c.id = m.conversation_id
         WHERE m.id = ? AND m.organization_id = ?'
    );
    $stmt->execute([$messageId, $organizationId]);
    $message = $stmt->fetch();

    if (!$message) {
        throw new RuntimeException('Message not found.');
    }

    try {
        if ($message['channel_type'] === 'website_chat') {
            db()->prepare('UPDATE messages SET status = \'sent\' WHERE id = ?')->execute([$messageId]);
            record_delivery_attempt($messageId, 'success', 'Delivered to website chat widget');
            return 'Delivered via website chat';
        }

        if ($message['channel_type'] === 'email') {
            $conversation = db()->prepare(
                'SELECT ct.email AS contact_email, ct.email_bounced_at, c.subject, c.external_thread_id
                 FROM conversations c INNER JOIN contacts ct ON ct.id = c.contact_id
                 WHERE c.id = ? AND c.organization_id = ?'
            );
            $conversation->execute([$message['conversation_id'], $organizationId]);
            $conversationRow = $conversation->fetch();

            if (!$conversationRow || !$conversationRow['contact_email']) {
                throw new RuntimeException('This contact has no email address on file.');
            }

            if ($conversationRow['email_bounced_at']) {
                throw new RuntimeException('Previous emails to this address bounced -- not sending again automatically.');
            }

            $providerMessageId = send_via_mailgun(
                $organizationId,
                $conversationRow['contact_email'],
                $conversationRow['subject'] ?? 'Re: your message',
                $message['body'],
                $conversationRow['external_thread_id']
            );

            db()->prepare(
                'UPDATE messages SET status = \'sent\', provider_message_id = ? WHERE id = ?'
            )->execute([$providerMessageId, $messageId]);

            record_delivery_attempt($messageId, 'success', 'Sent via Mailgun, id ' . $providerMessageId);
            return 'Sent via email';
        }

        if ($message['channel_type'] === 'telegram') {
            $contactRow = db()->prepare(
                'SELECT ct.channel_identities
                 FROM conversations c INNER JOIN contacts ct ON ct.id = c.contact_id
                 WHERE c.id = ? AND c.organization_id = ?'
            );
            $contactRow->execute([$message['conversation_id'], $organizationId]);
            $identities = json_decode((string) $contactRow->fetchColumn(), true) ?? [];
            $chatId = $identities['telegram'] ?? null;

            if (!$chatId) {
                throw new RuntimeException('No Telegram chat ID on file for this contact.');
            }

            send_via_telegram($organizationId, (string) $chatId, $message['body']);

            db()->prepare('UPDATE messages SET status = \'sent\' WHERE id = ?')->execute([$messageId]);
            record_delivery_attempt($messageId, 'success', 'Sent via Telegram');
            return 'Sent via Telegram';
        }

        throw new RuntimeException(
            'No sender implemented yet for channel "' . $message['channel_type'] . '" -- that arrives in its own phase.'
        );

    } catch (Throwable $e) {
        record_delivery_attempt($messageId, 'failed', $e->getMessage());
        throw $e;
    }
}


function record_delivery_attempt(int $messageId, string $status, ?string $response): void
{
    $countStmt = db()->prepare('SELECT COUNT(*) FROM message_delivery_attempts WHERE message_id = ?');
    $countStmt->execute([$messageId]);
    $attemptNumber = ((int) $countStmt->fetchColumn()) + 1;

    db()->prepare(
        'INSERT INTO message_delivery_attempts (message_id, attempt_number, status, provider_response)
         VALUES (?, ?, ?, ?)'
    )->execute([$messageId, $attemptNumber, $status, $response]);

    if ($status === 'failed') {
        db()->prepare('UPDATE messages SET status = \'failed\' WHERE id = ?')->execute([$messageId]);
    }
}


/** Always organization-scoped -- one business can never load another's conversation. */
function get_conversation(int $organizationId, int $conversationId): ?array
{
    $stmt = db()->prepare(
        'SELECT c.*, ct.name AS contact_name, ct.email AS contact_email, ct.phone AS contact_phone,
                ct.customer_id, u.name AS assigned_name
         FROM conversations c
         INNER JOIN contacts ct ON ct.id = c.contact_id
         LEFT JOIN users u ON u.id = c.assigned_to_user_id
         WHERE c.id = ? AND c.organization_id = ?
         LIMIT 1'
    );
    $stmt->execute([$conversationId, $organizationId]);
    $conversation = $stmt->fetch();

    if (!$conversation) {
        return null;
    }

    $messages = db()->prepare(
        'SELECT m.*, u.name AS sender_name
         FROM messages m
         LEFT JOIN users u ON u.id = m.sender_user_id
         WHERE m.conversation_id = ? ORDER BY m.created_at ASC'
    );
    $messages->execute([$conversationId]);
    $conversation['messages'] = $messages->fetchAll();

    return $conversation;
}


function mark_conversation_read(int $organizationId, int $conversationId): void
{
    db()->prepare(
        'UPDATE conversations SET unread_count = 0 WHERE id = ? AND organization_id = ?'
    )->execute([$conversationId, $organizationId]);
}


function assign_conversation(int $organizationId, int $conversationId, ?int $userId): void
{
    db()->prepare(
        'UPDATE conversations SET assigned_to_user_id = ? WHERE id = ? AND organization_id = ?'
    )->execute([$userId, $conversationId, $organizationId]);

    log_action(action: 'inbox.assigned', entityType: 'conversation', entityId: $conversationId, after: ['assigned_to_user_id' => $userId]);
}


function update_conversation_status(int $organizationId, int $conversationId, string $status): void
{
    $allowed = ['open', 'pending', 'resolved', 'archived'];

    if (!in_array($status, $allowed, true)) {
        throw new RuntimeException('Invalid status.');
    }

    db()->prepare(
        'UPDATE conversations SET status = ? WHERE id = ? AND organization_id = ?'
    )->execute([$status, $conversationId, $organizationId]);

    log_action(action: 'inbox.status_changed', entityType: 'conversation', entityId: $conversationId, after: ['status' => $status]);
}


/*
|--------------------------------------------------------------------------
| ATTACHMENTS
|--------------------------------------------------------------------------
| Stored OUTSIDE public/ (in storage/attachments/) so a file can only
| ever be reached through attachment_download.php, which checks that
| the person asking is actually logged into the right organization
| first. We never trust the browser's claimed file type -- we sniff
| the real one server-side and check it against an allow-list.
*/

const ALLOWED_ATTACHMENT_MIMES = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/pdf',
    'text/plain',
];

const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024; // 10MB

function store_attachment(int $organizationId, array $uploadedFile): array
{
    if (!isset($uploadedFile['tmp_name']) || !is_uploaded_file($uploadedFile['tmp_name'])) {
        throw new RuntimeException('Invalid upload.');
    }

    if ($uploadedFile['size'] > MAX_ATTACHMENT_BYTES) {
        throw new RuntimeException('Attachment is too large (10MB max).');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $realMime = $finfo->file($uploadedFile['tmp_name']);

    if (!in_array($realMime, ALLOWED_ATTACHMENT_MIMES, true)) {
        throw new RuntimeException('That file type isn\'t allowed.');
    }

    $extensionMap = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
        'image/webp' => 'webp', 'application/pdf' => 'pdf', 'text/plain' => 'txt',
    ];

    $storageDir = __DIR__ . '/../../storage/attachments/' . $organizationId;

    if (!is_dir($storageDir) && !mkdir($storageDir, 0755, true) && !is_dir($storageDir)) {
        throw new RuntimeException('Could not create attachment storage folder.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extensionMap[$realMime];
    $destination = $storageDir . '/' . $filename;

    if (!move_uploaded_file($uploadedFile['tmp_name'], $destination)) {
        throw new RuntimeException('Could not save attachment.');
    }

    return [
        'path' => $organizationId . '/' . $filename,
        'original_name' => basename($uploadedFile['name'] ?? 'attachment'),
        'mime' => $realMime,
        'size' => $uploadedFile['size'],
    ];
}
