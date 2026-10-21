<?php
/**
 * Counselor Student Details Audit Record API Endpoint
 */

require_once '../config/db.php';
require_once '../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || $_SESSION['role'] !== 'counselor') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized counselor access']);
    exit;
}

$studentId = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
if (empty($studentId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Student ID parameter is required']);
    exit;
}

$db = get_db_connection();

try {
    // 1. Fetch Student profile details
    $stmt = $db->prepare("
        SELECT p.name, p.phone, p.student_id AS card_num, p.department, p.bio, u.email 
        FROM students_profile p 
        JOIN users u ON p.user_id = u.id 
        WHERE p.user_id = ? 
        LIMIT 1
    ");
    $stmt->execute([$studentId]);
    $profile = $stmt->fetch();

    if (!$profile) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Student record not found']);
        exit;
    }

    // 2. Fetch assessment history logs
    try {
        $db->exec("ALTER TABLE self_assessments ADD COLUMN assessment_type VARCHAR(50) NOT NULL DEFAULT 'anxiety' AFTER student_id");
    } catch (Exception $schemaEx) {}
    try {
        $db->exec("ALTER TABLE self_assessments ADD COLUMN details JSON NULL AFTER category");
    } catch (Exception $schemaEx) {}

    $stmt = $db->prepare("SELECT id, assessment_type, answers, score, category, details, completed_at FROM self_assessments WHERE student_id = ? ORDER BY completed_at DESC");
    $stmt->execute([$studentId]);
    $assessments = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'data' => [
            'profile' => $profile,
            'assessments' => $assessments
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database query error: ' . $e->getMessage()]);
}
