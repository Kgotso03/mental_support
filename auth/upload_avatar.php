<?php
/**
 * Avatar Upload Endpoint
 */
require_once '../config/db.php';
require_once '../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!isset($_FILES['avatar'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No file was received. Please check file size limits.']);
    exit;
}

if ($_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    $errorMsg = 'Upload error code: ' . $_FILES['avatar']['error'];
    echo json_encode(['success' => false, 'message' => $errorMsg]);
    exit;
}

$file = $_FILES['avatar'];

// 2MB limit (matching common php.ini default)
if ($file['size'] > 2 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'File size exceeds 2MB limit']);
    exit;
}

// Verify it's a real image
$imageInfo = @getimagesize($file['tmp_name']);
if ($imageInfo === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Uploaded file is not a valid image.']);
    exit;
}

// Generate unique filename
$extension = pathinfo($file['name'], PATHINFO_EXTENSION);
if (empty($extension)) {
    // Fallback extension based on mime type
    $mimeToExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $extension = isset($mimeToExt[$imageInfo['mime']]) ? $mimeToExt[$imageInfo['mime']] : 'jpg';
}
$filename = 'avatar_' . $_SESSION['user_id'] . '_' . time() . '.' . $extension;
$uploadDir = __DIR__ . '/../uploads/avatars/';

// Ensure directory exists
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$destination = $uploadDir . $filename;

if (move_uploaded_file($file['tmp_name'], $destination)) {
    $db = get_db_connection();
    
    // Update user profile picture path
    $dbPath = 'uploads/avatars/' . $filename;
    $stmt = $db->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
    $stmt->execute([$dbPath, $_SESSION['user_id']]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Profile picture updated successfully',
        'data' => [
            'profile_picture' => $dbPath
        ]
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file']);
}
