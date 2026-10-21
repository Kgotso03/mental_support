<?php
/**
 * User Profile Update API Endpoint
 */

require_once '../config/db.php';
require_once '../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input)) {
    $input = $_POST;
}

$name = isset($input['name']) ? sanitize_input($input['name']) : '';
$userId = $_SESSION['user_id'];
$role = $_SESSION['role'];

if (empty($name)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Full name is required']);
    exit;
}

$db = get_db_connection();

try {
    if ($role === 'student') {
        $studentId = isset($input['student_id']) ? sanitize_input($input['student_id']) : '';
        $phone = isset($input['phone']) ? sanitize_input($input['phone']) : '';
        $department = isset($input['department']) ? sanitize_input($input['department']) : '';
        $bio = isset($input['bio']) ? sanitize_input($input['bio']) : '';

        // Check if student profile exists (or insert if missing, though it should exist)
        $stmt = $db->prepare("SELECT id FROM students_profile WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        
        if ($stmt->fetch()) {
            $stmt = $db->prepare("UPDATE students_profile SET name = ?, phone = ?, student_id = ?, department = ?, bio = ? WHERE user_id = ?");
            $stmt->execute([$name, $phone, $studentId, $department, $bio, $userId]);
        } else {
            $stmt = $db->prepare("INSERT INTO students_profile (user_id, name, phone, student_id, department, bio) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $name, $phone, $studentId, $department, $bio]);
        }
        $_SESSION['name'] = $name;
        
    } else if ($role === 'counselor') {
        $specialty = isset($input['specialty']) ? sanitize_input($input['specialty']) : '';
        $bio = isset($input['bio']) ? sanitize_input($input['bio']) : '';

        $stmt = $db->prepare("SELECT id FROM counselors WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);

        if ($stmt->fetch()) {
            $stmt = $db->prepare("UPDATE counselors SET name = ?, specialty = ?, bio = ? WHERE user_id = ?");
            $stmt->execute([$name, $specialty, $bio, $userId]);
        } else {
            $stmt = $db->prepare("INSERT INTO counselors (user_id, name, specialty, bio, status) VALUES (?, ?, ?, ?, 'pending')");
            $stmt->execute([$userId, $name, $specialty, $bio]);
        }
        $_SESSION['name'] = $name;
    } else {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Profile edits not allowed for this role']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Profile updated successfully',
        'data' => ['name' => $name]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Profile update error: ' . $e->getMessage()]);
}
