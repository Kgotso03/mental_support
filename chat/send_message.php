<?php
/**
 * Real-time Chat Sending API Endpoint
 */

require_once '../config/db.php';
require_once '../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$chatType = isset($input['chat_type']) ? sanitize_input($input['chat_type']) : 'direct';
$content = isset($input['content']) ? sanitize_input($input['content']) : '';

if (empty($content)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Message content cannot be empty']);
    exit;
}

$senderId = $_SESSION['user_id'];
$db = get_db_connection();

try {
    if ($chatType === 'direct') {
        $receiverId = isset($input['receiver_id']) ? intval($input['receiver_id']) : 0;
        if (empty($receiverId)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Receiver ID is required for direct chat']);
            exit;
        }

        // Insert direct chat message
        $stmt = $db->prepare("INSERT INTO messages (sender_id, receiver_id, content) VALUES (?, ?, ?)");
        $stmt->execute([$senderId, $receiverId, $content]);

        // Send a alert notification to receiver (only if chat is not actively read)
        $senderName = $_SESSION['name'];
        $stmt = $db->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)");
        $stmt->execute([$receiverId, "New direct message from $senderName: '$content'"]);

    } else if ($chatType === 'group') {
        if (!in_array($_SESSION['role'], ['student', 'counselor', 'admin'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Only authorized users can write to the group discussion board']);
            exit;
        }

        // Insert group discussion board message
        $stmt = $db->prepare("INSERT INTO group_discussions (sender_id, content) VALUES (?, ?)");
        $stmt->execute([$senderId, $content]);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid chat type specified']);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Message sent successfully']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Sending message failed: ' . $e->getMessage()]);
}
