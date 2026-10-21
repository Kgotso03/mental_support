<?php
/**
 * Lecturer – Student Progress Notes API
 * Department Access Policy: Lecturers can only view progress reports of students
 * in the SAME department they are registered to.
 */

require_once '../config/db.php';
require_once '../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || $_SESSION['role'] !== 'lecturer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized lecturer access']);
    exit;
}

$studentId   = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
$lecturerUid = $_SESSION['user_id'];

if (!$studentId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'student_id required']);
    exit;
}

$db = get_db_connection();

try {
    // 1. Fetch lecturer's department
    $stmtL = $db->prepare("SELECT department FROM lecturers WHERE user_id = ? LIMIT 1");
    $stmtL->execute([$lecturerUid]);
    $lecturer = $stmtL->fetch();
    $lecturerDept = strtolower(trim($lecturer['department'] ?? $_SESSION['department'] ?? ''));

    // 2. Fetch student's department
    $stmtS = $db->prepare("SELECT department FROM students_profile WHERE user_id = ? LIMIT 1");
    $stmtS->execute([$studentId]);
    $student = $stmtS->fetch();

    if (!$student) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Student record not found']);
        exit;
    }

    $studentDept = strtolower(trim($student['department'] ?? ''));

    // 3. Department access check
    if (empty($lecturerDept) || empty($studentDept) || $lecturerDept !== $studentDept) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'not allowed to view report for this student'
        ]);
        exit;
    }

    // Fetch all completed appointments with a submitted progress note
    $stmt = $db->prepare("
        SELECT a.id AS appointment_id,
               a.date, a.time, a.session_type,
               a.counselor_progress_note,
               a.progress_note_submitted_at,
               c.name AS counselor_name, c.specialty AS counselor_specialty
        FROM appointments a
        JOIN counselors c ON a.counselor_id = c.user_id
        WHERE a.student_id = ?
          AND a.status = 'completed'
          AND a.counselor_progress_note IS NOT NULL
          AND a.counselor_progress_note != ''
        ORDER BY a.progress_note_submitted_at DESC
    ");
    $stmt->execute([$studentId]);
    $reports = $stmt->fetchAll();

    echo json_encode(['success' => true, 'data' => $reports]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
