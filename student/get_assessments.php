<?php
/**
 * Student Self-Assessment History API Endpoint
 */

require_once '../config/db.php';
require_once '../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || $_SESSION['role'] !== 'student') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized student access']);
    exit;
}

$studentId = $_SESSION['user_id'];
$db = get_db_connection();

try {
    // Proactive schema alignment: ensure assessment_type and details columns exist
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
        'data' => $assessments
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database query error: ' . $e->getMessage()]);
}
