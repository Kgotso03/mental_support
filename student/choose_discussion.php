<?php
/**
 * Student Group Discussion Choice Endpoint
 */

require_once '../config/db.php';
require_once '../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || $_SESSION['role'] !== 'student') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized student access']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = get_json_input();
if (empty($input)) {
    $input = $_POST;
}

$choice = isset($input['choice']) ? sanitize_input($input['choice']) : '';

if ($choice !== 'video' && $choice !== 'chat') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid choice. Must be "video" or "chat".']);
    exit;
}

$studentId = $_SESSION['user_id'];
$db = get_db_connection();

try {
    $stmt = $db->prepare("UPDATE students_profile SET discussion_choice = ?, group_recommended = 0 WHERE user_id = ?");
    $stmt->execute([$choice, $studentId]);

    echo json_encode(['success' => true, 'message' => 'Your discussion preference has been saved successfully.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
