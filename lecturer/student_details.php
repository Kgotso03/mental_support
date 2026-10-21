<?php
/**
 * Lecturer Student Details API Endpoint
 * Department Access Policy: Lecturers can only view student details/reports
 * for students registered in the SAME department.
 */

require_once '../config/db.php';
require_once '../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || $_SESSION['role'] !== 'lecturer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized lecturer access']);
    exit;
}

$studentId = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
if (empty($studentId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Student ID parameter is required']);
    exit;
}

$lecturerUid = $_SESSION['user_id'];
$db = get_db_connection();

try {
    // 1. Fetch lecturer profile (department and university)
    $stmtL = $db->prepare("SELECT department, university FROM lecturers WHERE user_id = ? LIMIT 1");
    $stmtL->execute([$lecturerUid]);
    $lecturer = $stmtL->fetch();

    $lecturerDept = strtolower(trim($lecturer['department'] ?? $_SESSION['department'] ?? ''));

    // 2. Fetch target student profile
    $stmtS = $db->prepare("
        SELECT p.name, p.phone, p.student_id AS card_num, p.department, p.bio, p.university, u.email 
        FROM students_profile p 
        JOIN users u ON p.user_id = u.id 
        WHERE p.user_id = ?
        LIMIT 1
    ");
    $stmtS->execute([$studentId]);
    $profile = $stmtS->fetch();

    if (!$profile) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Student record not found']);
        exit;
    }

    $studentDept = strtolower(trim($profile['department'] ?? ''));

    // 3. Department restriction check: lecturer can only view students in their same department
    if (empty($lecturerDept) || empty($studentDept) || $lecturerDept !== $studentDept) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'not allowed to view report for this student'
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'profile' => $profile
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database query error: ' . $e->getMessage()]);
}
