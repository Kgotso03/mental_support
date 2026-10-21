<?php
require_once '../config/db.php';
require_once '../config/session.php';

header('Content-Type: application/json; charset=utf-8');

// Bypass session check
$userId = 1; 
$partnerId = isset($_GET['partner_id']) ? intval($_GET['partner_id']) : 2;

$db = get_db_connection();

try {
    $query = "
        SELECT m.id, m.sender_id, m.receiver_id, m.content, m.timestamp, COALESCE(c.name, s.name, u.role) AS sender_name 
        FROM messages m 
        JOIN users u ON m.sender_id = u.id 
        LEFT JOIN counselors c ON u.id = c.user_id 
        LEFT JOIN students_profile s ON u.id = s.user_id 
        WHERE ((m.sender_id = :userId AND m.receiver_id = :partnerId) 
           OR (m.sender_id = :partnerId AND m.receiver_id = :userId))
    ";
    
    $params = [
        'userId' => $userId,
        'partnerId' => $partnerId
    ];

    $query .= " ORDER BY m.timestamp ASC";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $messages = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'data' => $messages
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database fetching error: ' . $e->getMessage()]);
}
