<?php
/**
 * Administrator User Management API Endpoint
 */

require_once '../config/db.php';
require_once '../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || $_SESSION['role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized admin access']);
    exit;
}

$db = get_db_connection();

    // Ensure universities table exists
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS universities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL UNIQUE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}

// Auto-create deletion_logs table if it doesn't exist
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS deletion_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            table_name VARCHAR(100) NOT NULL,
            record_id INT NOT NULL,
            deleted_by INT NOT NULL,
            deletion_reason TEXT NOT NULL,
            deleted_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Handle POST Actions
        $input = json_decode(file_get_contents('php://input'), true);
        if (empty($input)) {
            $input = $_POST;
        }
        $action = isset($input['action']) ? sanitize_input($input['action']) : '';

        if ($action === 'approve_counselor') {
            $counselorId = isset($input['user_id']) ? intval($input['user_id']) : 0;

            if (empty($counselorId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Counselor ID is required']);
                exit;
            }

            $db->beginTransaction();

            // Activate counselor
            $stmt = $db->prepare("UPDATE counselors SET status = 'active' WHERE user_id = ?");
            $stmt->execute([$counselorId]);

            // Send alert notification to counselor user
            $stmt = $db->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, 'Your counselor profile credentials have been approved by the administrator. You can now receive student counseling sessions.', 0)");
            $stmt->execute([$counselorId]);

            $db->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Counselor account has been approved and activated.'
            ]);
            exit;

        } else if ($action === 'add_counselor') {
            $email = isset($input['email']) ? sanitize_input($input['email']) : '';
            $name = isset($input['name']) ? sanitize_input($input['name']) : '';
            $password = isset($input['password']) ? $input['password'] : '';
            $specialty = isset($input['specialty']) ? sanitize_input($input['specialty']) : '';
            $bio = isset($input['bio']) ? sanitize_input($input['bio']) : '';

            if (empty($email) || empty($name) || empty($password) || empty($specialty)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'All fields (name, email, password, specialty) are required']);
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

            // Check if email already exists
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'Email address is already registered']);
                exit;
            }

            $password_hash = password_hash($password, PASSWORD_BCRYPT);

            $db->beginTransaction();

            $stmt = $db->prepare("INSERT INTO users (email, password_hash, role) VALUES (?, ?, 'counselor')");
            $stmt->execute([$email, $password_hash]);
            $userId = $db->lastInsertId();

            $stmt = $db->prepare("INSERT INTO counselors (user_id, name, specialty, bio, status) VALUES (?, ?, ?, ?, 'active')");
            $stmt->execute([$userId, $name, $specialty, $bio]);

            $db->commit();

            // Send Welcome Email
            require_once '../config/mail.php';
            $subject = "Welcome to the Digital Mental Health Support Platform!";
            $bodyHtml = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px;'>
                    <h2 style='color: #0f766e;'>Hello " . htmlspecialchars($name) . ",</h2>
                    <p>Welcome to the <strong>Digital Mental Health Support Platform</strong>. An administrator has registered you as a <strong>Counselor</strong>.</p>
                    <p>Your login credentials are as follows:</p>
                    <p><strong>Email:</strong> " . htmlspecialchars($email) . "</p>
                    <p><strong>Password:</strong> " . htmlspecialchars($password) . "</p>
                    <p>You can now log in to the dashboard to access your consultations and messaging portal.</p>
                    <hr style='border: 0; border-top: 1px solid #e2e8f0; margin: 20px 0;' />
                    <p style='font-size: 0.875rem; color: #64748b;'>This is an automated email notification. Please do not reply directly to this message.</p>
                </div>
            ";
            send_email($email, $name, $subject, $bodyHtml);

            echo json_encode(['success' => true, 'message' => 'Counselor added successfully!']);
            exit;

        } else if ($action === 'add_lecturer') {
            $email = isset($input['email']) ? sanitize_input($input['email']) : '';
            $name = isset($input['name']) ? sanitize_input($input['name']) : '';
            $password = isset($input['password']) ? $input['password'] : '';
            $department = isset($input['department']) ? sanitize_input($input['department']) : '';

            if (empty($email) || empty($name) || empty($password) || empty($department)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'All fields (name, email, password, department) are required']);
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

            // Check if email already exists
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'Email address is already registered']);
                exit;
            }

            $password_hash = password_hash($password, PASSWORD_BCRYPT);
            
            // Get admin's university to assign to lecturer (safely)
            $university = $_SESSION['university'] ?? '';
            try {
                $adminId = intval($_SESSION['user_id']);
                $stmt = $db->prepare("SELECT university FROM admin WHERE user_id = ? LIMIT 1");
                $stmt->execute([$adminId]);
                $admin = $stmt->fetch();
                if ($admin && !empty($admin['university'])) {
                    $university = $admin['university'];
                }
            } catch (Exception $e) {}

            $db->beginTransaction();

            $stmt = $db->prepare("INSERT INTO users (email, password_hash, role) VALUES (?, ?, 'lecturer')");
            $stmt->execute([$email, $password_hash]);
            $userId = $db->lastInsertId();

            $stmt = $db->prepare("INSERT INTO lecturers (user_id, name, department, university) VALUES (?, ?, ?, ?)");
            $stmt->execute([$userId, $name, $department, $university]);

            $db->commit();

            // Send Welcome Email
            require_once '../config/mail.php';
            $subject = "Welcome to the Digital Mental Health Support Platform!";
            $bodyHtml = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px;'>
                    <h2 style='color: #0f766e;'>Hello " . htmlspecialchars($name) . ",</h2>
                    <p>Welcome to the <strong>Digital Mental Health Support Platform</strong>. An administrator has registered you as a <strong>Lecturer</strong>.</p>
                    <p style='margin-bottom: 20px;'>You can now check up on your students' wellness level and assessment logs.</p>
                    <p>Your login credentials are as follows:</p>
                    <p><strong>Email:</strong> " . htmlspecialchars($email) . "</p>
                    <p><strong>Password:</strong> " . htmlspecialchars($password) . "</p>
                    <p>You can now log in to the dashboard to access the student progress portal.</p>
                    <hr style='border: 0; border-top: 1px solid #e2e8f0; margin: 20px 0;' />
                    <p style='font-size: 0.875rem; color: #64748b;'>This is an automated email notification. Please do not reply directly to this message.</p>
                </div>
            ";
            send_email($email, $name, $subject, $bodyHtml);

            echo json_encode(['success' => true, 'message' => 'Lecturer added successfully!']);
            exit;

        } else if ($action === 'delete_user') {
            $targetId     = isset($input['user_id'])       ? intval($input['user_id'])       : 0;
            $adminPassword = isset($input['admin_password']) ? $input['admin_password']        : '';
            $reason       = isset($input['reason'])        ? trim($input['reason'])           : '';

            if (empty($targetId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'User ID is required']);
                exit;
            }

            // Verify admin password
            if ($adminPassword !== 'Mental268') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Incorrect admin password. Access denied.']);
                exit;
            }

            // Ensure reason is provided
            if (empty($reason)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'A deletion reason is required']);
                exit;
            }

            // Prevent self-deletion
            if ($targetId === intval($_SESSION['user_id'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'You cannot delete your own account']);
                exit;
            }

            // Delete user (Foreign key ON DELETE CASCADE handles related tables)
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$targetId]);

            // Log deletion event
            $adminId = intval($_SESSION['user_id']);
            $logStmt = $db->prepare("
                INSERT INTO deletion_logs (table_name, record_id, deleted_by, deletion_reason, deleted_at)
                VALUES ('users', ?, ?, ?, NOW())
            ");
            $logStmt->execute([$targetId, $adminId, $reason]);

            echo json_encode([
                'success' => true,
                'message' => 'User account has been deleted and the action has been logged.'
            ]);
            exit;

        } else if ($action === 'update_password') {
            $targetId      = isset($input['user_id'])        ? intval($input['user_id'])        : 0;
            $newPassword   = isset($input['new_password'])   ? $input['new_password']            : '';
            $adminPassword = isset($input['admin_password']) ? $input['admin_password']           : '';

            if (empty($targetId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'User ID is required']);
                exit;
            }

            // Verify admin password
            if ($adminPassword !== 'Mental268') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Incorrect admin password. Access denied.']);
                exit;
            }

            // Validate new password
            if (empty($newPassword) || strlen($newPassword) < 6) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'New password must be at least 6 characters']);
                exit;
            }

            // Prevent changing own password through this endpoint
            if ($targetId === intval($_SESSION['user_id'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'You cannot change your own password through user management']);
                exit;
            }

            // Hash and update
            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
            $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$hashedPassword, $targetId]);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'User not found or password unchanged']);
                exit;
            }

            // Log password change
            try {
                $db->exec("
                    CREATE TABLE IF NOT EXISTS password_change_logs (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        changed_by INT NOT NULL,
                        changed_at DATETIME NOT NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
                $adminId = intval($_SESSION['user_id']);
                $logStmt = $db->prepare("
                    INSERT INTO password_change_logs (user_id, changed_by, changed_at)
                    VALUES (?, ?, NOW())
                ");
                $logStmt->execute([$targetId, $adminId]);
            } catch (Exception $logErr) {
                // Non-critical — password was already updated
            }

            echo json_encode([
                'success' => true,
                'message' => 'Password has been updated successfully.'
            ]);
            exit;

        } else if ($action === 'add_university') {
            $name = isset($input['name']) ? sanitize_input($input['name']) : '';

            if (empty($name)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'University name is required']);
                exit;
            }

            // Check if university already exists
            $stmt = $db->prepare("SELECT id FROM universities WHERE name = ? LIMIT 1");
            $stmt->execute([$name]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'University already exists']);
                exit;
            }

            $stmt = $db->prepare("INSERT INTO universities (name) VALUES (?)");
            $stmt->execute([$name]);

            echo json_encode(['success' => true, 'message' => 'University added successfully!']);
            exit;

        } else if ($action === 'delete_university') {
            $id = isset($input['id']) ? intval($input['id']) : 0;

            if (empty($id)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'University ID is required']);
                exit;
            }

            // Perform deletion
            $stmt = $db->prepare("DELETE FROM universities WHERE id = ?");
            $stmt->execute([$id]);

            echo json_encode(['success' => true, 'message' => 'University removed successfully!']);
            exit;

        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action parameter']);
            exit;
        }

    } else if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Handle GET Requests
        $role = isset($_GET['role']) ? sanitize_input($_GET['role']) : '';
        $action = isset($_GET['action']) ? sanitize_input($_GET['action']) : '';

        if ($action === 'get_details') {
            $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
            $targetRole = isset($_GET['role']) ? sanitize_input($_GET['role']) : '';

            if (empty($userId) || empty($targetRole)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'User ID and role are required']);
                exit;
            }

            if ($targetRole === 'student') {
                $stmt = $db->prepare("
                    SELECT u.id, u.email, u.created_at, s.name, s.phone, s.student_id, s.department, s.university, s.bio
                    FROM users u
                    JOIN students_profile s ON u.id = s.user_id
                    WHERE u.id = ? AND u.role = 'student'
                    LIMIT 1
                ");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();

                if ($user) {
                    // Fetch self-assessments history
                    $stmtAsmt = $db->prepare("
                        SELECT id, assessment_type, score, category, details, completed_at
                        FROM self_assessments
                        WHERE student_id = ?
                        ORDER BY completed_at DESC
                    ");
                    $stmtAsmt->execute([$userId]);
                    $user['assessments'] = $stmtAsmt->fetchAll();

                    // Fetch therapy appointments history
                    $stmtApt = $db->prepare("
                        SELECT a.id, a.date, a.time, a.status, a.session_type, a.counselor_progress_note, a.progress_note_submitted_at,
                               c.name AS counselor_name, c.specialty AS counselor_specialty
                        FROM appointments a
                        LEFT JOIN counselors c ON a.counselor_id = c.user_id
                        WHERE a.student_id = ?
                        ORDER BY a.date DESC, a.time DESC
                    ");
                    $stmtApt->execute([$userId]);
                    $user['appointments'] = $stmtApt->fetchAll();
                }
            } else if ($targetRole === 'counselor') {
                $stmt = $db->prepare("
                    SELECT u.id, u.email, u.created_at, c.name, c.specialty, c.status, c.bio
                    FROM users u
                    JOIN counselors c ON u.id = c.user_id
                    WHERE u.id = ? AND u.role = 'counselor'
                    LIMIT 1
                ");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
            } else if ($targetRole === 'lecturer') {
                $stmt = $db->prepare("
                    SELECT u.id, u.email, u.created_at, l.name, l.department, l.university
                    FROM users u
                    JOIN lecturers l ON u.id = l.user_id
                    WHERE u.id = ? AND u.role = 'lecturer'
                    LIMIT 1
                ");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid role designated']);
                exit;
            }

            if (!$user) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'User not found']);
                exit;
            }

            echo json_encode([
                'success' => true,
                'data'    => $user
            ]);
            exit;
        }

        if ($action === 'get_universities') {
            // Return list of universities (id, name)
            $stmt = $db->query("SELECT id, name FROM universities ORDER BY name ASC");
            $unis = $stmt->fetchAll();
            echo json_encode([
                'success' => true,
                'data'    => $unis
            ]);
            exit;
        }

        if ($role === 'student') {
            // List ALL students (no university restriction - admin sees everyone)
            $stmt = $db->query("
                SELECT u.id, u.email, u.created_at, 
                       COALESCE(s.name, u.email) AS name, 
                       COALESCE(s.phone, 'N/A') AS phone, 
                       COALESCE(s.student_id, 'N/A') AS student_id, 
                       COALESCE(s.department, 'N/A') AS department, 
                       COALESCE(s.university, 'N/A') AS university
                FROM users u
                LEFT JOIN students_profile s ON u.id = s.user_id
                WHERE u.role = 'student'
                ORDER BY name ASC
            ");
            $students = $stmt->fetchAll();

            // Map database id to frontend user_id
            foreach ($students as &$s) {
                $s['user_id'] = $s['id'];
            }

            echo json_encode([
                'success' => true,
                'data'    => $students
            ]);
            exit;

        } else if ($role === 'counselor') {
            // List all counselors
            $stmt = $db->query("
                SELECT u.id, u.email, u.created_at, 
                       COALESCE(c.name, u.email) AS name, 
                       COALESCE(c.specialty, 'General Counseling') AS specialty, 
                       COALESCE(c.status, 'active') AS status, 
                       COALESCE(c.bio, '') AS bio
                FROM users u
                LEFT JOIN counselors c ON u.id = c.user_id
                WHERE u.role = 'counselor'
                ORDER BY name ASC
            ");
            $counselors = $stmt->fetchAll();

            // Map database id to frontend user_id
            foreach ($counselors as &$c) {
                $c['user_id'] = $c['id'];
            }

            echo json_encode([
                'success' => true,
                'data'    => $counselors
            ]);
            exit;

        } else if ($role === 'lecturer') {
            // List all lecturers
            $stmt = $db->query("
                SELECT u.id, u.email, u.created_at, 
                       COALESCE(l.name, u.email) AS name, 
                       COALESCE(l.department, 'N/A') AS department, 
                       COALESCE(l.university, 'N/A') AS university
                FROM users u
                LEFT JOIN lecturers l ON u.id = l.user_id
                WHERE u.role = 'lecturer'
                ORDER BY name ASC
            ");
            $lecturers = $stmt->fetchAll();

            // Map database id to frontend user_id
            foreach ($lecturers as &$l) {
                $l['user_id'] = $l['id'];
            }

            echo json_encode([
                'success' => true,
                'data'    => $lecturers
            ]);
            exit;

        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Role parameter is required']);
            exit;
        }
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Administrator registry action error: ' . $e->getMessage()]);
}
