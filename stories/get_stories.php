<?php
/**
 * Public Stories Feed API Endpoint
 */

require_once '../config/db.php';
require_once '../config/session.php';

header('Content-Type: application/json; charset=utf-8');

$db = get_db_connection();

try {
    // Select approved stories, joining with the student profile to fetch names
    $stmt = $db->query("
        SELECT s.id, s.title, s.content, s.is_anonymous, s.created_at, p.name AS student_name 
        FROM shared_stories s 
        JOIN students_profile p ON s.student_id = p.user_id 
        WHERE s.status = 'approved' 
        ORDER BY s.created_at DESC
    ");
    $stories = $stmt->fetchAll();

    // Mask name if anonymous flag is true
    foreach ($stories as &$story) {
        if ($story['is_anonymous']) {
            $story['student_name'] = 'Anonymous Student';
        }
    }

    echo json_encode([
        'success' => true,
        'data' => $stories
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Stories loading error: ' . $e->getMessage()]);
}
