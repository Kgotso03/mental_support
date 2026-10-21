<?php
/**
 * Lecturer Students List API Endpoint
 * Returns basic student profile info only — NO assessment data.
 */

require_once '../config/db.php';
require_once '../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || $_SESSION['role'] !== 'lecturer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized lecturer access']);
    exit;
}

$university = isset($_SESSION['university']) ? $_SESSION['university'] : '';

if (empty($university)) {
    echo json_encode(['success' => true, 'data' => []]);
    exit;
}

$db = get_db_connection();

try {
    // Fetch students at the same university — basic info only, no assessment data
    $stmt = $db->prepare("
        SELECT 
            sp.user_id, 
            sp.name, 
            sp.student_id, 
            sp.department, 
            sp.university,
            u.email
        FROM students_profile sp
        JOIN users u ON sp.user_id = u.id
        WHERE sp.university = ?
        ORDER BY sp.name ASC
    ");
    $stmt->execute([$university]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $students
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database query error: ' . $e->getMessage()]);
}
