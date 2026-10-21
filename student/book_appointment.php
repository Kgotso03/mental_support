<?php
/**
 * Student Appointment Booking API Endpoint
 */

require_once '../config/db.php';
require_once '../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || $_SESSION['role'] !== 'student') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized student access']);
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

$counselorId = isset($input['counselor_id']) ? intval($input['counselor_id']) : 0;
$date = isset($input['date']) ? sanitize_input($input['date']) : '';
$time = isset($input['time']) ? sanitize_input($input['time']) : '';
$sessionType = isset($input['session_type']) ? sanitize_input($input['session_type']) : 'video';
$isUrgent = isset($input['is_urgent']) && $input['is_urgent'] ? 1 : 0;
$urgencyDisorder = isset($input['urgency_disorder']) ? sanitize_input($input['urgency_disorder']) : '';


if (empty($counselorId) || empty($date) || empty($time)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Counselor, date, and time slot are required']);
    exit;
}

// 1. Working Hours check (8:00 am to 4:00 pm)
$timeVal = strtotime($time);
$minTime = strtotime("08:00");
$maxTime = strtotime("16:00");
if ($timeVal < $minTime || $timeVal > $maxTime) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Counselor working hours start from 8:00 AM to 4:00 PM. Please select an available time slot between 08:00 and 16:00.'
    ]);
    exit;
}

// 2. Past date/time check - reject bookings placed in the past
$requestedTimestamp = strtotime("$date $time");
if ($requestedTimestamp < time()) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Cannot book appointments in the past. Please select a future date and time slot.'
    ]);
    exit;
}

$validSessionTypes = ['video', 'chat', 'group'];
if (!in_array($sessionType, $validSessionTypes)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid session type chosen']);
    exit;
}

$studentId = $_SESSION['user_id'];
$studentName = $_SESSION['name'];
$db = get_db_connection();

// Compulsory check: student MUST have completed at least one assessment to determine risk before booking
$stmtAsmtCheck = $db->prepare("SELECT COUNT(*) as count FROM self_assessments WHERE student_id = ?");
$stmtAsmtCheck->execute([$studentId]);
if ($stmtAsmtCheck->fetch()['count'] == 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'has_no_assessment' => true,
        'message' => 'Compulsory Requirement: You must complete at least one self-assessment to determine your risk level before booking a consultation.'
    ]);
    exit;
}

// Auto-migrate: ensure is_urgent and urgency_disorder columns exist
try { $db->exec("ALTER TABLE appointments ADD COLUMN is_urgent TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE appointments ADD COLUMN urgency_disorder VARCHAR(100) DEFAULT NULL"); } catch (Exception $e) {}

try {
    // Verify that counselor exists and is approved
    $stmt = $db->prepare("SELECT u.id, u.email, c.name FROM users u JOIN counselors c ON u.id = c.user_id WHERE u.id = ? AND u.role = 'counselor' AND c.status = 'active' LIMIT 1");
    $stmt->execute([$counselorId]);
    $counselor = $stmt->fetch();

    if (!$counselor) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Selected counselor was not found or is currently inactive']);
        exit;
    }

    $counselorName = $counselor['name'];
    $counselorEmail = $counselor['email'];

    // 2. Fully Booked check (max 9 slots per day)
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM appointments WHERE counselor_id = ? AND date = ? AND status != 'rejected'");
    $stmt->execute([$counselorId, $date]);
    $dailyCount = $stmt->fetch()['count'];
    if ($dailyCount >= 9) {
        // Find another active counselor with fewer than 9 appointments on this date
        $stmtRec = $db->prepare("
            SELECT c.user_id, c.name, COUNT(a.id) as app_count 
            FROM counselors c 
            LEFT JOIN appointments a ON c.user_id = a.counselor_id AND a.date = ? AND a.status != 'rejected'
            WHERE c.status = 'active' AND c.user_id != ?
            GROUP BY c.user_id, c.name
            HAVING app_count < 9
            ORDER BY app_count ASC
            LIMIT 1
        ");
        $stmtRec->execute([$date, $counselorId]);
        $freeCounselor = $stmtRec->fetch();

        http_response_code(400);
        if ($freeCounselor) {
            echo json_encode([
                'success' => false,
                'counselor_fully_booked' => true,
                'recommended_counselor_id' => $freeCounselor['user_id'],
                'recommended_counselor_name' => $freeCounselor['name'],
                'message' => "Counselor $counselorName is fully booked on $date. We recommend booking with Counselor {$freeCounselor['name']}, who is available on this date."
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'counselor_fully_booked' => true,
                'message' => "Counselor $counselorName is fully booked on $date, and all other counselors are also fully booked. Please choose a different date."
            ]);
        }
        exit;
    }

    // 3. Overlap check (1-hour interval duration)
    $stmt = $db->prepare("
        SELECT COUNT(*) as count FROM appointments 
        WHERE counselor_id = ? 
          AND date = ? 
          AND status != 'rejected'
          AND time < ADDTIME(?, '01:00:00') 
          AND ? < ADDTIME(time, '01:00:00')
    ");
    $stmt->execute([$counselorId, $date, $time, $time]);
    $overlapCount = $stmt->fetch()['count'];
    if ($overlapCount > 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => "Counselor $counselorName is already booked during the requested period. Please choose a different time slot."
        ]);
        exit;
    }

    // Generate a unique WebRTC meeting channel link
    $meetingRoomKey = 'room_' . bin2hex(random_bytes(8));
    $meetingLink = 'https://meet.jit.si/' . $meetingRoomKey; // Secure jitsi bridge URL

    $db->beginTransaction();

    // Verify group recommendation if selecting group discussions session type
    if ($sessionType === 'group') {
        $stmt = $db->prepare("SELECT group_recommended FROM students_profile WHERE user_id = ? LIMIT 1");
        $stmt->execute([$studentId]);
        $profile = $stmt->fetch();
        if (!$profile || !$profile['group_recommended']) {
            $db->rollBack();
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Group discussions must be recommended by a counselor before booking.']);
            exit;
        }
    }

    // Insert appointment
    $stmt = $db->prepare("INSERT INTO appointments (student_id, counselor_id, date, time, status, meeting_link, session_type, is_urgent, urgency_disorder) VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?)");
    $stmt->execute([$studentId, $counselorId, $date, $time, $meetingLink, $sessionType, $isUrgent, $urgencyDisorder ?: null]);

    // If it's a group session, clear the group_recommended status
    if ($sessionType === 'group') {
        $stmt = $db->prepare("UPDATE students_profile SET group_recommended = 0, discussion_choice = 'chat' WHERE user_id = ?");
        $stmt->execute([$studentId]);
    }

    // Send a system notification to the counselor
    if ($isUrgent) {
        $notificationMsg = "🚨 URGENT – {$sessionType} session booking from $studentName (Disorder: $urgencyDisorder) on $date at $time. This student requires IMMEDIATE ATTENTION.";
    } else {
        $notificationMsg = "New " . $sessionType . " session booking request received from student $studentName for $date at $time.";
    }
    $stmt = $db->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)");
    $stmt->execute([$counselorId, $notificationMsg]);

    $db->commit();

    // Send Booking Notification Emails
    require_once '../config/mail.php';
    
    // Email 1: To the Student
    $studentEmail = $_SESSION['email'];
    $studentSubject = "Booking Request Pending: Consultation on $date";
    $studentBody = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px;'>
            <h2 style='color: #0f766e;'>Hello " . htmlspecialchars($studentName) . ",</h2>
            <p>Your session booking request has been submitted successfully and is pending approval.</p>
            <table style='width: 100%; border-collapse: collapse; margin: 15px 0;'>
                <tr style='background-color: #f8fafc;'><td style='padding: 10px; font-weight: bold; border: 1px solid #e2e8f0;'>Counselor</td><td style='padding: 10px; border: 1px solid #e2e8f0;'>" . htmlspecialchars($counselorName) . "</td></tr>
                <tr><td style='padding: 10px; font-weight: bold; border: 1px solid #e2e8f0;'>Date</td><td style='padding: 10px; border: 1px solid #e2e8f0;'>$date</td></tr>
                <tr style='background-color: #f8fafc;'><td style='padding: 10px; font-weight: bold; border: 1px solid #e2e8f0;'>Time</td><td style='padding: 10px; border: 1px solid #e2e8f0;'>$time</td></tr>
                <tr><td style='padding: 10px; font-weight: bold; border: 1px solid #e2e8f0;'>Type</td><td style='padding: 10px; border: 1px solid #e2e8f0;'>" . ucfirst($sessionType) . " Consultation</td></tr>
            </table>
            <p>You will receive an email update as soon as the counselor accepts or rejects your request.</p>
            <hr style='border: 0; border-top: 1px solid #e2e8f0; margin: 20px 0;' />
            <p style='font-size: 0.875rem; color: #64748b;'>This is an automated email notification. Please do not reply directly to this message.</p>
        </div>
    ";
    send_email($studentEmail, $studentName, $studentSubject, $studentBody);

    // Email 2: To the Counselor
    $counselorSubject = $isUrgent
        ? "🚨 URGENT Booking – Immediate Attention Required: $studentName"
        : "New Booking Request Received: $studentName";
    $urgentBanner = $isUrgent ? "
        <div style='background:#dc2626;color:white;padding:14px 20px;border-radius:6px;margin-bottom:20px;font-weight:bold;font-size:16px;'>
            🚨 URGENT – This student has been flagged as requiring IMMEDIATE ATTENTION based on their mental health assessment results ($urgencyDisorder).
        </div>
    " : '';
    $counselorBody = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px;'>
            <h2 style='color: #0f766e;'>Hello " . htmlspecialchars($counselorName) . ",</h2>
            $urgentBanner
            <p>You have received a new consultation booking request from student <strong>" . htmlspecialchars($studentName) . "</strong>.</p>
            <table style='width: 100%; border-collapse: collapse; margin: 15px 0;'>
                <tr style='background-color: #f8fafc;'><td style='padding: 10px; font-weight: bold; border: 1px solid #e2e8f0;'>Student</td><td style='padding: 10px; border: 1px solid #e2e8f0;'>" . htmlspecialchars($studentName) . "</td></tr>
                <tr><td style='padding: 10px; font-weight: bold; border: 1px solid #e2e8f0;'>Date</td><td style='padding: 10px; border: 1px solid #e2e8f0;'>$date</td></tr>
                <tr style='background-color: #f8fafc;'><td style='padding: 10px; font-weight: bold; border: 1px solid #e2e8f0;'>Time</td><td style='padding: 10px; border: 1px solid #e2e8f0;'>$time</td></tr>
                <tr><td style='padding: 10px; font-weight: bold; border: 1px solid #e2e8f0;'>Type</td><td style='padding: 10px; border: 1px solid #e2e8f0;'>" . ucfirst($sessionType) . " Consultation</td></tr>
            </table>
            <p>Please log in to your Counselor Dashboard to accept or reject this request.</p>
            <hr style='border: 0; border-top: 1px solid #e2e8f0; margin: 20px 0;' />
            <p style='font-size: 0.875rem; color: #64748b;'>This is an automated email notification. Please do not reply directly to this message.</p>
        </div>
    ";
    send_email($counselorEmail, $counselorName, $counselorSubject, $counselorBody);

    echo json_encode([
        'success' => true,
        'message' => 'Appointment booked successfully and is pending counselor approval.'
    ]);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Booking error: ' . $e->getMessage()]);
}
