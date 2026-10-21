<?php
/**
 * MySQL Database Connection Configuration
 * Establishes a reusable PDO connection to the 'mental_support' database.
 */

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', ''); // No password, default WAMP / XAMPP configuration
define('DB_NAME', 'mental_support');

$db_connection = null;

/**
 * Returns a secure PDO instance for database transactions.
 */
function get_db_connection() {
    global $db_connection;
    if ($db_connection === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $db_connection = new PDO($dsn, DB_USER, DB_PASS, $options);
            
            // Run self-healing schema updates
            try {
                // Modify status column ENUM to include 'missing'
                $db_connection->exec("ALTER TABLE appointments MODIFY COLUMN status ENUM('pending', 'accepted', 'rejected', 'completed', 'missing') DEFAULT 'pending'");
            } catch (Exception $e) {}

            try {
                // Modify users role ENUM to include 'lecturer'
                $db_connection->exec("ALTER TABLE users MODIFY COLUMN role ENUM('student', 'counselor', 'admin', 'lecturer') NOT NULL");
            } catch (Exception $e) {}

            try {
                // Create lecturers table
                $db_connection->exec("
                    CREATE TABLE IF NOT EXISTS lecturers (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL UNIQUE,
                        name VARCHAR(100) NOT NULL,
                        department VARCHAR(100) NOT NULL,
                        university VARCHAR(150) NOT NULL,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
            } catch (Exception $e) {}

            try {
                // Create admin table
                $db_connection->exec("
                    CREATE TABLE IF NOT EXISTS admin (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL UNIQUE,
                        name VARCHAR(100) NOT NULL,
                        university VARCHAR(150) DEFAULT NULL,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
            } catch (Exception $e) {}

            try {
                $stmt = $db_connection->query("SHOW COLUMNS FROM appointments LIKE 'session_started'");
                if (!$stmt->fetch()) {
                    $db_connection->exec("ALTER TABLE appointments ADD COLUMN session_started TINYINT(1) DEFAULT 0 AFTER session_type");
                }
            } catch (Exception $e) {}

            try {
                $stmt = $db_connection->query("SHOW COLUMNS FROM appointments LIKE 'started_at'");
                if (!$stmt->fetch()) {
                    $db_connection->exec("ALTER TABLE appointments ADD COLUMN started_at DATETIME DEFAULT NULL AFTER session_started");
                }
            } catch (Exception $e) {}

            try {
                $stmt = $db_connection->query("SHOW COLUMNS FROM students_profile LIKE 'discussion_choice'");
                if (!$stmt->fetch()) {
                    $db_connection->exec("ALTER TABLE students_profile ADD COLUMN discussion_choice ENUM('video', 'chat', 'none') DEFAULT 'none' AFTER group_recommended");
                }
            } catch (Exception $e) {}

            // Create webrtc_rooms table (WebRTC signaling — supports appointments and custom room names)
            try {
                $db_connection->exec("
                    CREATE TABLE IF NOT EXISTS webrtc_rooms (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        appointment_id INT NOT NULL DEFAULT 0,
                        room_name VARCHAR(128) DEFAULT NULL,
                        room_token VARCHAR(64) NOT NULL,
                        is_active TINYINT(1) DEFAULT 1,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_apt (appointment_id),
                        INDEX idx_name (room_name),
                        INDEX idx_token (room_token)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
            } catch (Exception $e) {}

            // Create webrtc_signals table (WebRTC offer/answer/ICE exchange)
            try {
                $db_connection->exec("
                    CREATE TABLE IF NOT EXISTS webrtc_signals (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        room_token VARCHAR(64) NOT NULL,
                        sender_role ENUM('counselor','student') NOT NULL,
                        signal_type ENUM('offer','answer','ice') NOT NULL,
                        payload MEDIUMTEXT NOT NULL,
                        consumed TINYINT(1) DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_room (room_token, consumed, sender_role)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
            } catch (Exception $e) {}

            // Create community_video_rooms table
            try {
                $db_connection->exec("
                    CREATE TABLE IF NOT EXISTS community_video_rooms (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        room_name VARCHAR(128) NOT NULL UNIQUE,
                        topic VARCHAR(255) DEFAULT 'General Mental Health Support & Group Discussion',
                        moderator_id INT DEFAULT NULL,
                        moderator_name VARCHAR(100) DEFAULT NULL,
                        is_active TINYINT(1) DEFAULT 1,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
            } catch (Exception $e) {}

            // Create community_video_participants table
            try {
                $db_connection->exec("
                    CREATE TABLE IF NOT EXISTS community_video_participants (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        room_name VARCHAR(128) NOT NULL,
                        user_id INT NOT NULL,
                        user_name VARCHAR(100) NOT NULL,
                        role VARCHAR(50) NOT NULL,
                        is_moderator TINYINT(1) DEFAULT 0,
                        audio_muted TINYINT(1) DEFAULT 0,
                        video_off TINYINT(1) DEFAULT 0,
                        last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        UNIQUE KEY uq_room_user (room_name, user_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
            } catch (Exception $e) {}

            // Add ai_summary column to appointments if missing
            try {
                $stmt = $db_connection->query("SHOW COLUMNS FROM appointments LIKE 'ai_summary'");
                if (!$stmt->fetch()) {
                    $db_connection->exec("ALTER TABLE appointments ADD COLUMN ai_summary MEDIUMTEXT DEFAULT NULL");
                }
            } catch (Exception $e) {}

            // Add counselor_progress_note column to appointments if missing
            try {
                $stmt = $db_connection->query("SHOW COLUMNS FROM appointments LIKE 'counselor_progress_note'");
                if (!$stmt->fetch()) {
                    $db_connection->exec("ALTER TABLE appointments ADD COLUMN counselor_progress_note TEXT DEFAULT NULL");
                }
            } catch (Exception $e) {}

            // Add progress_note_submitted_at column to appointments if missing
            try {
                $stmt = $db_connection->query("SHOW COLUMNS FROM appointments LIKE 'progress_note_submitted_at'");
                if (!$stmt->fetch()) {
                    $db_connection->exec("ALTER TABLE appointments ADD COLUMN progress_note_submitted_at DATETIME DEFAULT NULL");
                }
            } catch (Exception $e) {}

            // Run session status auto-updater logic
            check_and_update_appointment_statuses($db_connection);


        } catch (PDOException $e) {
            // Return uniform JSON response in case connection fails
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'Database connection failed: ' . $e->getMessage()
            ]);
            exit;
        }
    }
    return $db_connection;
}

/**
 * Automatically updates appointment states based on scheduled time and activity.
 */
function check_and_update_appointment_statuses($db) {
    // Prevent nested calls / infinite recursion if we do other operations
    static $running = false;
    if ($running) return;
    $running = true;

    try {
        // 1. Mark completed automatically (scheduled time + 1 hour has passed, and was started)
        $stmt = $db->query("
            SELECT DISTINCT student_id FROM appointments
            WHERE status = 'accepted'
              AND session_started = 1
              AND TIMESTAMP(date, time) <= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $studentIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($studentIds)) {
            $db->exec("
                UPDATE appointments
                SET status = 'completed'
                WHERE status = 'accepted'
                  AND session_started = 1
                  AND TIMESTAMP(date, time) <= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            
            require_once __DIR__ . '/mail.php';
            // Also insert notifications and send emails for these students
            foreach ($studentIds as $sId) {
                $notifMsg = "Your counseling session has ended and has been marked as completed automatically.";
                $stmtNotif = $db->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)");
                $stmtNotif->execute([$sId, $notifMsg]);

                // Fetch student info
                $stmtUser = $db->prepare("SELECT u.email, sp.name FROM users u JOIN students_profile sp ON u.id = sp.user_id WHERE u.id = ? LIMIT 1");
                $stmtUser->execute([$sId]);
                $studentInfo = $stmtUser->fetch();
                if ($studentInfo) {
                    $subject = "Session Completed Automatically";
                    $bodyHtml = "
                        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px;'>
                            <h2 style='color: #0f766e;'>Hello " . htmlspecialchars($studentInfo['name']) . ",</h2>
                            <p>Your scheduled counseling session has ended and was marked as completed automatically.</p>
                            <p>If you need further support, please log in to your dashboard to view story sharing boards, discussion forums, or book another session.</p>
                            <hr style='border: 0; border-top: 1px solid #e2e8f0; margin: 20px 0;' />
                            <p style='font-size: 0.875rem; color: #64748b;'>This is an automated email notification. Please do not reply directly to this message.</p>
                        </div>
                    ";
                    send_email($studentInfo['email'], $studentInfo['name'], $subject, $bodyHtml);
                }
            }
        }
        
        // 2. Mark missing automatically (scheduled time + 15 minutes has passed, and was NOT started)
        $stmtMissing = $db->query("
            SELECT id, student_id, counselor_id, date, time FROM appointments
            WHERE status IN ('pending', 'accepted')
              AND session_started = 0
              AND TIMESTAMP(date, time) <= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $missingAppointments = $stmtMissing->fetchAll();
        
        if (!empty($missingAppointments)) {
            $db->exec("
                UPDATE appointments
                SET status = 'missing'
                WHERE status IN ('pending', 'accepted')
                  AND session_started = 0
                  AND TIMESTAMP(date, time) <= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            ");
            
            require_once __DIR__ . '/mail.php';
            // Send notifications and emails for missing sessions
            foreach ($missingAppointments as $apt) {
                $notifMsg = "Your scheduled session on {$apt['date']} at {$apt['time']} was closed and recorded as missing because the session did not take place within 15 minutes.";
                $stmtNotif = $db->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)");
                $stmtNotif->execute([$apt['student_id'], $notifMsg]);
                
                // Also notify the counselor
                $notifMsgC = "The session scheduled with your student on {$apt['date']} at {$apt['time']} was automatically closed and marked as missing.";
                $stmtNotifC = $db->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)");
                $stmtNotifC->execute([$apt['counselor_id'], $notifMsgC]);

                // Fetch Student email and name
                $stmtS = $db->prepare("SELECT u.email, sp.name FROM users u JOIN students_profile sp ON u.id = sp.user_id WHERE u.id = ? LIMIT 1");
                $stmtS->execute([$apt['student_id']]);
                $sInfo = $stmtS->fetch();

                // Fetch Counselor email and name
                $stmtC = $db->prepare("SELECT u.email, c.name FROM users u JOIN counselors c ON u.id = c.user_id WHERE u.id = ? LIMIT 1");
                $stmtC->execute([$apt['counselor_id']]);
                $cInfo = $stmtC->fetch();

                // Send Student email
                if ($sInfo) {
                    $subjectS = "Missed Consultation Appointment Notification";
                    $bodyHtmlS = "
                        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px;'>
                            <h2 style='color: #0f766e;'>Hello " . htmlspecialchars($sInfo['name']) . ",</h2>
                            <p>Your scheduled counseling session on <strong>{$apt['date']}</strong> at <strong>{$apt['time']}</strong> has been closed and recorded as <strong>missing</strong> because the session did not start within 15 minutes of its scheduled time.</p>
                            <p>If you still wish to consult with a counselor, please book a new session in your Student Dashboard.</p>
                            <hr style='border: 0; border-top: 1px solid #e2e8f0; margin: 20px 0;' />
                            <p style='font-size: 0.875rem; color: #64748b;'>This is an automated email notification. Please do not reply directly to this message.</p>
                        </div>
                    ";
                    send_email($sInfo['email'], $sInfo['name'], $subjectS, $bodyHtmlS);
                }

                // Send Counselor email
                if ($cInfo) {
                    $subjectC = "Missed Consultation Appointment Notification";
                    $bodyHtmlC = "
                        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px;'>
                            <h2 style='color: #0f766e;'>Hello " . htmlspecialchars($cInfo['name']) . ",</h2>
                            <p>The scheduled counseling session with your student on <strong>{$apt['date']}</strong> at <strong>{$apt['time']}</strong> has been closed and marked as <strong>missing</strong> because it was not started within 15 minutes of the scheduled time.</p>
                            <hr style='border: 0; border-top: 1px solid #e2e8f0; margin: 20px 0;' />
                            <p style='font-size: 0.875rem; color: #64748b;'>This is an automated email notification. Please do not reply directly to this message.</p>
                        </div>
                    ";
                    send_email($cInfo['email'], $cInfo['name'], $subjectC, $bodyHtmlC);
                }
            }
        }
    } catch (Exception $e) {
        // Log or ignore to avoid disrupting connection setup
    } finally {
        $running = false;
    }
}

