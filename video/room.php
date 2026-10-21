<?php
/**
 * Video Room API
 * POST  {appointment_id, room_name} — create/retrieve room token for an appointment or a custom room name
 * DELETE ?room_token=...            — mark room inactive, purge its signals
 */

require_once '../config/db.php';
require_once '../config/session.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, ngrok-skip-browser-warning, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$db   = get_db_connection();
$role = $_SESSION['role'];
$uid  = $_SESSION['user_id'];

// ── DELETE: end room ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $token = isset($_GET['room_token']) ? trim($_GET['room_token']) : '';
    if (!$token) {
        // Also accept JSON body
        $body = json_decode(file_get_contents('php://input'), true);
        $token = $body['room_token'] ?? '';
    }
    if (!$token) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'room_token required']);
        exit;
    }
    try {
        // Find appointment_id first
        $stmt = $db->prepare("SELECT appointment_id FROM webrtc_rooms WHERE room_token = ? LIMIT 1");
        $stmt->execute([$token]);
        $room = $stmt->fetch();

        if ($room && $room['appointment_id'] > 0) {
            $aptId = intval($room['appointment_id']);
            
            // Mark appointment as completed
            $db->prepare("UPDATE appointments SET status = 'completed' WHERE id = ? AND status = 'accepted'")->execute([$aptId]);


            // Get counselor & student IDs
            $stmtApt = $db->prepare("SELECT student_id, counselor_id, date, time FROM appointments WHERE id = ? LIMIT 1");
            $stmtApt->execute([$aptId]);
            $apt = $stmtApt->fetch();
            if ($apt) {
                // Send notifications
                $notifMsg = "Your video consultation session on {$apt['date']} at {$apt['time']} has been completed.";
                $db->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)")->execute([$apt['student_id'], $notifMsg]);
                
                $notifMsgC = "The video consultation session with your student on {$apt['date']} at {$apt['time']} has been completed.";
                $db->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)")->execute([$apt['counselor_id'], $notifMsgC]);
            }
        }

        $db->prepare("UPDATE webrtc_rooms SET is_active = 0 WHERE room_token = ?")->execute([$token]);
        $db->prepare("DELETE FROM webrtc_signals WHERE room_token = ?")->execute([$token]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── POST: create or get room ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) $body = $_POST;

$aptId = intval($body['appointment_id'] ?? 0);
$roomName = isset($body['room_name']) ? trim($body['room_name']) : '';

if (!$aptId && !$roomName) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'appointment_id or room_name required']);
    exit;
}

try {
    if ($aptId > 0) {
        // Verify the user belongs to this appointment
        if ($role === 'student') {
            $stmt = $db->prepare("SELECT id FROM appointments WHERE id = ? AND student_id = ? AND status = 'accepted' LIMIT 1");
        } else if ($role === 'counselor') {
            $stmt = $db->prepare("SELECT id FROM appointments WHERE id = ? AND counselor_id = ? AND status = 'accepted' LIMIT 1");
        } else {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        $stmt->execute([$aptId, $uid]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Appointment not found or not accepted']);
            exit;
        }

        // Check for existing active room for this appointment
        $existing = $db->prepare("SELECT room_token FROM webrtc_rooms WHERE appointment_id = ? AND is_active = 1 LIMIT 1");
        $existing->execute([$aptId]);
        $row = $existing->fetch();
    } else {
        // Custom room name (e.g. group discussion)
        // Check for existing active room for this room name
        $existing = $db->prepare("SELECT room_token FROM webrtc_rooms WHERE room_name = ? AND is_active = 1 LIMIT 1");
        $existing->execute([$roomName]);
        $row = $existing->fetch();
    }

    if ($row) {
        // Return existing token
        echo json_encode(['success' => true, 'room_token' => $row['room_token'], 'created' => false]);
        exit;
    }

    // Create new room token
    $token = bin2hex(random_bytes(16)); // 32 hex chars
    if ($aptId > 0) {
        $ins = $db->prepare("INSERT INTO webrtc_rooms (appointment_id, room_token, is_active) VALUES (?, ?, 1)");
        $ins->execute([$aptId, $token]);
    } else {
        $ins = $db->prepare("INSERT INTO webrtc_rooms (room_name, room_token, is_active) VALUES (?, ?, 1)");
        $ins->execute([$roomName, $token]);
    }

    echo json_encode(['success' => true, 'room_token' => $token, 'created' => true]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
