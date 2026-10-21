<?php
/**
 * Real-time Chat Pull API Endpoint
 */

require_once '../config/db.php';
require_once '../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$action = isset($_GET['action']) ? sanitize_input($_GET['action']) : (isset($_GET['chat_type']) ? sanitize_input($_GET['chat_type']) : 'direct');
$userId = $_SESSION['user_id'];
$role = $_SESSION['role'];
$db = get_db_connection();

try {
    if ($action === 'list_contacts') {
        if ($role === 'student') {
            // Students can chat with all active counselors
            $stmt = $db->query("SELECT user_id AS id, name, specialty FROM counselors WHERE status = 'active' ORDER BY name ASC");
            $contacts = $stmt->fetchAll();
        } else if ($role === 'counselor') {
            // Counselors can chat with students who have booked sessions with them
            $stmt = $db->prepare("
                SELECT DISTINCT u.id, s.name, s.department 
                FROM users u 
                JOIN students_profile s ON u.id = s.user_id 
                JOIN appointments a ON u.id = a.student_id 
                WHERE a.counselor_id = ?
                ORDER BY s.name ASC
            ");
            $stmt->execute([$userId]);
            $contacts = $stmt->fetchAll();
        } else {
            $contacts = [];
        }

        echo json_encode([
            'success' => true,
            'data' => $contacts
        ]);

    } else if ($action === 'direct') {
        $partnerId = isset($_GET['partner_id']) ? intval($_GET['partner_id']) : 0;
        $lastTimestamp = isset($_GET['last_timestamp']) ? sanitize_input($_GET['last_timestamp']) : '';

        if (empty($partnerId)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Partner ID is required']);
            exit;
        }

        // Build base query
        $query = "
            SELECT m.id, m.sender_id, m.receiver_id, m.content, m.timestamp, COALESCE(c.name, s.name, u.role) AS sender_name 
            FROM messages m 
            JOIN users u ON m.sender_id = u.id 
            LEFT JOIN counselors c ON u.id = c.user_id 
            LEFT JOIN students_profile s ON u.id = s.user_id 
            WHERE ((m.sender_id = :userId1 AND m.receiver_id = :partnerId1) 
               OR (m.sender_id = :partnerId2 AND m.receiver_id = :userId2))
        ";
        
        $params = [
            'userId1' => $userId,
            'partnerId1' => $partnerId,
            'partnerId2' => $partnerId,
            'userId2' => $userId
        ];

        // Delta loading support
        if (!empty($lastTimestamp)) {
            $query .= " AND m.timestamp > :lastTimestamp";
            $params['lastTimestamp'] = $lastTimestamp;
        }

        $query .= " ORDER BY m.timestamp ASC";

        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $messages = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'data' => $messages
        ]);

    } else if ($action === 'group') {
        $lastTimestamp = isset($_GET['last_timestamp']) ? sanitize_input($_GET['last_timestamp']) : '';

        $query = "
            SELECT g.id, g.sender_id, g.content, g.timestamp, COALESCE(c.name, s.name, a.name, u.role) AS sender_name 
            FROM group_discussions g 
            JOIN users u ON g.sender_id = u.id
            LEFT JOIN counselors c ON u.id = c.user_id 
            LEFT JOIN students_profile s ON u.id = s.user_id 
            LEFT JOIN admin a ON u.id = a.user_id
        ";
        
        $params = [];

        if (!empty($lastTimestamp)) {
            $query .= " WHERE g.timestamp > :lastTimestamp";
            $params['lastTimestamp'] = $lastTimestamp;
        }

        $query .= " ORDER BY g.timestamp ASC LIMIT 80";

        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $messages = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'data' => $messages
        ]);

    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action parameter']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database fetching error: ' . $e->getMessage()]);
}
