<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

/*
| No require_auth() here -- this is called by anonymous visitors on
| the BUSINESS's own website, which is a different domain from this
| app. That's exactly why the checks below (widget key + origin +
| rate limit) exist: there's no login to rely on instead.
*/

header('Content-Type: application/json');

$widgetKey = $_GET['key'] ?? '';
$account = $widgetKey ? get_channel_account_by_widget_key($widgetKey) : null;

$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
$referer = $_SERVER['HTTP_REFERER'] ?? null;

if (!$account || !is_allowed_widget_origin($account, $origin, $referer)) {
    // Deliberately vague, and no CORS header sent -- the browser will
    // block the response from ever being read by a page on a domain
    // we didn't approve, regardless of what this endpoint returns.
    http_response_code(403);
    echo json_encode(['error' => 'Not authorized.']);
    exit;
}

// From here on, we know the request is coming from a domain this
// business actually approved -- safe to answer it, and safe to tell
// the browser it's allowed to read the response.
header('Access-Control-Allow-Origin: ' . $origin);
header('Vary: Origin');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if (!check_widget_rate_limit($ip)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many messages -- please slow down.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];

$action = $input['action'] ?? '';
$organizationId = (int) $account['organization_id'];

// Simple spam trap: a real visitor never fills this hidden field in.
if (!empty($input['website'])) {
    echo json_encode(['ok' => true]); // pretend success, do nothing
    exit;
}

if ($action === 'start') {
    $name = trim((string) ($input['name'] ?? ''));
    $contact = find_or_create_contact($organizationId, $name ?: null, null, null);

    echo json_encode([
        'visitor_token' => sign_visitor_token((int) $contact['id']),
        'welcome_message' => json_decode((string) $account['public_config'], true)['welcome_message'] ?? 'Hi! How can we help?',
    ]);
    exit;
}

if ($action === 'send') {
    $contactId = verify_visitor_token((string) ($input['visitor_token'] ?? ''));
    $body = trim((string) ($input['message'] ?? ''));

    if (!$contactId) {
        http_response_code(401);
        echo json_encode(['error' => 'Session expired -- please refresh the chat.']);
        exit;
    }

    if ($body === '' || mb_strlen($body) > 2000) {
        http_response_code(422);
        echo json_encode(['error' => 'Message must be between 1 and 2000 characters.']);
        exit;
    }

    try {
        $result = receive_inbound_message_for_known_contact(
            $organizationId, $contactId, 'website_chat', (int) $account['id'], $body
        );

        echo json_encode([
            'conversation_id' => $result['conversation_id'],
            'message_id' => $result['message_id'],
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not send message.']);
    }
    exit;
}

if ($action === 'poll') {
    $contactId = verify_visitor_token((string) ($input['visitor_token'] ?? ''));
    $conversationId = filter_var($input['conversation_id'] ?? null, FILTER_VALIDATE_INT);
    $afterId = (int) ($input['after_id'] ?? 0);

    if (!$contactId || !$conversationId) {
        http_response_code(401);
        echo json_encode(['error' => 'Session expired.']);
        exit;
    }

    // Confirms this conversation really belongs to this contact AND
    // this organization before returning anything from it.
    $check = db()->prepare(
        'SELECT id FROM conversations WHERE id = ? AND organization_id = ? AND contact_id = ?'
    );
    $check->execute([$conversationId, $organizationId, $contactId]);

    if (!$check->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Not authorized.']);
        exit;
    }

    $messages = db()->prepare(
        "SELECT id, body, created_at FROM messages
         WHERE conversation_id = ? AND direction = 'outbound' AND id > ?
         ORDER BY id ASC"
    );
    $messages->execute([$conversationId, $afterId]);

    echo json_encode(['messages' => $messages->fetchAll()]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action.']);
