<?php
/**
 * Start Session API Endpoint
 */

require_once '../config/db.php';
require_once '../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
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

$appointmentId = isset($input['appointment_id']) ? intval($input['appointment_id']) : 0;

if (empty($appointmentId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Appointment ID is required']);
    exit;
}

$userId = $_SESSION['user_id'];
$role = $_SESSION['role'];
$db = get_db_connection();

try {
    // Verify appointment access and status
    if ($role === 'student') {
        $stmt = $db->prepare("SELECT id, status, session_started FROM appointments WHERE id = ? AND student_id = ? LIMIT 1");
    } else if ($role === 'counselor') {
        $stmt = $db->prepare("SELECT id, status, session_started FROM appointments WHERE id = ? AND counselor_id = ? LIMIT 1");
    } else {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized user role']);
        exit;
    }

    $stmt->execute([$appointmentId, $userId]);
    $appointment = $stmt->fetch();

    if (!$appointment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Appointment not found or access denied']);
        exit;
    }

    if ($appointment['status'] !== 'accepted') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Only accepted appointments can be started']);
        exit;
    }

    // Update if not already started
    if (!$appointment['session_started']) {
        $stmtUpdate = $db->prepare("UPDATE appointments SET session_started = 1, started_at = NOW() WHERE id = ?");
        $stmtUpdate->execute([$appointmentId]);
    }

    echo json_encode(['success' => true, 'message' => 'Session successfully marked as started']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
