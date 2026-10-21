<?php
/**
 * Counselor Progress Report API
 * GET  ?appointment_id=N  — returns existing progress note for an appointment
 * POST {appointment_id, note} — saves/submits the counselor's progress note
 */

require_once '../config/db.php';
require_once '../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || $_SESSION['role'] !== 'counselor') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = get_db_connection();
$counselorId = $_SESSION['user_id'];

// ── GET: Fetch existing note ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $aptId = isset($_GET['appointment_id']) ? intval($_GET['appointment_id']) : 0;
    if (!$aptId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'appointment_id required']);
        exit;
    }

    $stmt = $db->prepare("
        SELECT a.id, a.counselor_progress_note, a.progress_note_submitted_at,
               a.date, a.time, a.session_type,
               sp.name AS student_name, sp.department
        FROM appointments a
        JOIN students_profile sp ON a.student_id = sp.user_id
        WHERE a.id = ? AND a.counselor_id = ? AND a.status = 'completed'
        LIMIT 1
    ");
    $stmt->execute([$aptId, $counselorId]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Completed appointment not found']);
        exit;
    }

    echo json_encode(['success' => true, 'data' => $row]);
    exit;
}

// ── POST: Submit Progress Note ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $aptId = isset($input['appointment_id']) ? intval($input['appointment_id']) : 0;
    $note  = isset($input['note']) ? trim($input['note']) : '';

    if (!$aptId || empty($note)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'appointment_id and note are required']);
        exit;
    }

    // Ensure the appointment belongs to this counselor and is completed
    $stmt = $db->prepare("SELECT id, student_id FROM appointments WHERE id = ? AND counselor_id = ? AND status = 'completed' LIMIT 1");
    $stmt->execute([$aptId, $counselorId]);
    $apt = $stmt->fetch();

    if (!$apt) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Appointment not found or not yet completed']);
        exit;
    }

    $stmt = $db->prepare("UPDATE appointments SET counselor_progress_note = ?, progress_note_submitted_at = NOW() WHERE id = ?");
    $stmt->execute([$note, $aptId]);

    // Notify the student that a progress note is available
    $notifMsg = "Your counselor has submitted a progress note from your recent consultation.";
    $db->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)")->execute([$apt['student_id'], $notifMsg]);

    echo json_encode(['success' => true, 'message' => 'Progress note saved successfully.']);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
