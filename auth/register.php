<?php
/**
 * User Account Registration API Endpoint
 */

require_once '../config/db.php';
require_once '../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = isset($_GET['action']) ? sanitize_input($_GET['action']) : '';
    if ($action === 'get_universities') {
        $db = get_db_connection();
        $stmt = $db->query("SELECT id, name FROM universities ORDER BY name ASC");
        $unis = $stmt->fetchAll();
        echo json_encode([
            'success' => true,
            'data'    => $unis
        ]);
        exit;
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action parameter']);
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

$role = isset($input['role']) ? sanitize_input($input['role']) : 'student';
$email = isset($input['email']) ? sanitize_input($input['email']) : '';
$name = isset($input['name']) ? sanitize_input($input['name']) : '';
$password = isset($input['password']) ? $input['password'] : '';

if ($role !== 'student') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Only student self-registration is allowed']);
    exit;
}

if (empty($email) || empty($name) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'All fields are required']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email address format']);
    exit;
}

if (strlen($password) < 6) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']);
    exit;
}

$db = get_db_connection();

try {
    // Proactive schema alignment: ensure university column exists
    try {
        $db->exec("ALTER TABLE students_profile ADD COLUMN university VARCHAR(150) DEFAULT NULL AFTER name");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE admin ADD COLUMN university VARCHAR(150) DEFAULT NULL AFTER name");
    } catch (Exception $e) {}

    // Check if email already exists
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Email address is already registered']);
        exit;
    }

    $password_hash = password_hash($password, PASSWORD_BCRYPT);

    $studentId = isset($input['student_id']) ? sanitize_input($input['student_id']) : '';
    $university = isset($input['university']) ? sanitize_input($input['university']) : '';

    if (empty($university)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'University/college is required for students.']);
        exit;
    }

    // Validate university exists in predefined list
    $stmt = $db->prepare("SELECT id FROM universities WHERE name = ?");
    $stmt->execute([$university]);
    if (!$stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'University not recognized. Please select a valid university.']);
        exit;
    }

    // Check if student_id is already registered under ANY tertiary (to prevent multiple registrations)
    if (!empty($studentId)) {
        $stmt = $db->prepare("SELECT s.university FROM students_profile s WHERE s.student_id = ? LIMIT 1");
        $stmt->execute([$studentId]);
        $existing_profile = $stmt->fetch();
        if ($existing_profile) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => "This Student ID is already registered under a tertiary (" . ($existing_profile['university'] ?: 'N/A') . "). You cannot register under multiple tertiaries unless you are removed from the one you are registered under."
            ]);
            exit;
        }
    }

    // Use SQL transactions to ensure both tables insert successfully
    $db->beginTransaction();

    $stmt = $db->prepare("INSERT INTO users (email, password_hash, role) VALUES (?, ?, ?)");
    $stmt->execute([$email, $password_hash, $role]);
    $userId = $db->lastInsertId();

    $phone = isset($input['phone']) ? sanitize_input($input['phone']) : '';
    $department = isset($input['department']) ? sanitize_input($input['department']) : '';
    $bio = isset($input['student_bio']) ? sanitize_input($input['student_bio']) : (isset($input['bio']) ? sanitize_input($input['bio']) : '');

    $stmt = $db->prepare("INSERT INTO students_profile (user_id, name, university, phone, student_id, department, bio) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $name, $university, $phone, $studentId, $department, $bio]);

    $db->commit();

    // Send Welcome Email
    require_once '../config/mail.php';
    $subject = "Welcome to the Digital Mental Health Support Platform!";
    $bodyHtml = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px;'>
            <h2 style='color: #0f766e;'>Hello " . htmlspecialchars($name) . ",</h2>
            <p>Welcome to the <strong>Digital Mental Health Support Platform</strong>. Your account has been registered successfully as a <strong>Student</strong>.</p>
            <p>Our platform offers confidential counseling consultations, anonymous story sharing, peer discussion forums, and mental health self-assessments.</p>
            <p>You can now log in to the dashboard to begin booking sessions, scheduling counseling, or accessing self-assessments.</p>
            <hr style='border: 0; border-top: 1px solid #e2e8f0; margin: 20px 0;' />
            <p style='font-size: 0.875rem; color: #64748b;'>This is an automated email notification. Please do not reply directly to this message.</p>
        </div>
    ";
    send_email($email, $name, $subject, $bodyHtml);

    echo json_encode(['success' => true, 'message' => 'Registration successful! You can now log in.']);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Registration error: ' . $e->getMessage()]);
}
