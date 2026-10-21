<?php
/**
 * User Account Authentication (Login) API Endpoint
 */

require_once '../config/db.php';
require_once '../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input)) {
    $input = $_POST;
}

$email = isset($input['email']) ? sanitize_input($input['email']) : '';
$password = isset($input['password']) ? $input['password'] : '';

if (empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email and password are required']);
    exit;
}

$db = get_db_connection();

try {
    $stmt = $db->prepare("SELECT id, email, password_hash, role, profile_picture FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
        exit;
    }

    $userId = $user['id'];
    $role = $user['role'];
    $userName = '';

    // Enforce role verification policies and fetch name
    if ($role === 'student') {
        $stmt = $db->prepare("SELECT name, university FROM students_profile WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $profile = $stmt->fetch();
        $userName = $profile ? $profile['name'] : 'Student';
        $_SESSION['university'] = $profile ? $profile['university'] : '';
    } else if ($role === 'counselor') {
        $stmt = $db->prepare("SELECT name, status FROM counselors WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $counselor = $stmt->fetch();

        if (!$counselor) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Counselor record not found']);
            exit;
        }

        if ($counselor['status'] !== 'active') {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Your account is pending administrator approval. Please try again later.'
            ]);
            exit;
        }
        $userName = $counselor['name'];
    } else if ($role === 'admin') {
        $stmt = $db->prepare("SELECT name, university FROM admin WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $admin = $stmt->fetch();
        $userName = $admin ? $admin['name'] : 'System Admin';
        $_SESSION['university'] = $admin ? $admin['university'] : '';
    } else if ($role === 'lecturer') {
        $stmt = $db->prepare("SELECT name, department, university FROM lecturers WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $lecturer = $stmt->fetch();
        $userName = $lecturer ? $lecturer['name'] : 'Lecturer';
        $_SESSION['department'] = $lecturer ? $lecturer['department'] : '';
        $_SESSION['university'] = $lecturer ? $lecturer['university'] : '';
    }

    // Set secure session parameters
    $_SESSION['user_id'] = $userId;
    $_SESSION['email'] = $user['email'];
    $_SESSION['role'] = $role;
    $_SESSION['name'] = $userName;
    $_SESSION['last_activity'] = time();

    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'data' => [
            'user' => [
                'id' => $userId,
                'email' => $user['email'],
                'role' => $role,
                'name' => $userName,
                'profile_picture' => $user['profile_picture'],
                'university' => isset($_SESSION['university']) ? $_SESSION['university'] : ''
            ],
            'redirect' => 'dashboard_' . $role . '.html'
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Authentication error: ' . $e->getMessage()]);
}
