<?php
/**
 * Counselor Appointment Reschedule API Endpoint
 */

require_once '../config/db.php';
require_once '../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || $_SESSION['role'] !== 'counselor') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized counselor access']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$appointmentId = isset($input['appointment_id']) ? intval($input['appointment_id']) : 0;
$newCounselorId = isset($input['new_counselor_id']) ? intval($input['new_counselor_id']) : 0;
$newDate = isset($input['new_date']) ? sanitize_input($input['new_date']) : '';
$newTime = isset($input['new_time']) ? sanitize_input($input['new_time']) : '';

if (empty($appointmentId) || empty($newCounselorId) || empty($newDate) || empty($newTime)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// 1. Working Hours check (8:30 am to 5:30 pm, last session starts at 4:30 pm)
$timeVal = strtotime($newTime);
$minTime = strtotime("08:30");
$maxTime = strtotime("16:30");
if ($timeVal < $minTime || $timeVal > $maxTime) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Counselor working hours are 8:30 am to 5:30 pm. Since sessions last 1 hour, rescheduled sessions must start between 08:30 and 16:30.'
    ]);
    exit;
}

// 2. Past date/time check - reject rescheduling to a past time
$requestedTimestamp = strtotime("$newDate $newTime");
if ($requestedTimestamp < time()) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Cannot reschedule appointments to a past date or time slot.'
    ]);
    exit;
}

$currentCounselorId = $_SESSION['user_id'];
$counselorName = $_SESSION['name'];
$db = get_db_connection();

try {
    // Verify that the appointment exists and is assigned to this counselor
    $stmt = $db->prepare("SELECT student_id, date, time, is_urgent FROM appointments WHERE id = ? AND counselor_id = ? LIMIT 1");
    $stmt->execute([$appointmentId, $currentCounselorId]);
    $appointment = $stmt->fetch();

    if (!$appointment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Session appointment not found or access denied']);
        exit;
    }

    $studentId = $appointment['student_id'];

    // High Risk restriction check (Score >= 90% or is_urgent == 1)
    $isHighRisk = (intval($appointment['is_urgent']) === 1);
    
    if (!$isHighRisk) {
        $stmtScore = $db->prepare("
            SELECT score, assessment_type FROM self_assessments 
            WHERE student_id = ? 
            ORDER BY completed_at DESC, id DESC LIMIT 1
        ");
        $stmtScore->execute([$studentId]);
        $lastAsmt = $stmtScore->fetch();

        if ($lastAsmt && $lastAsmt['score'] !== null) {
            $maxScores = [
                'anxiety' => 9, 'depression' => 9, 'bipolar' => 9,
                'ptsd' => 6, 'stress' => 6, 'self_esteem' => 6, 'combined' => 45
            ];
            $score = intval($lastAsmt['score']);
            $type  = isset($lastAsmt['assessment_type']) ? $lastAsmt['assessment_type'] : 'combined';
            $max   = isset($maxScores[$type]) ? $maxScores[$type] : 45;
            if ($max > 0 && ($score / $max) >= 0.90) {
                $isHighRisk = true;
            }
        }
    }

    if ($isHighRisk) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'high_risk_blocked' => true,
            'message' => 'High Risk Restriction: Students with high-risk assessment scores (≥90% or urgent distress) cannot be rescheduled. They must be attended to immediately at their requested time slot.'
        ]);
        exit;
    }

    // 2. Fully Booked check for new counselor on new date (excluding this appointment itself)
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM appointments WHERE counselor_id = ? AND date = ? AND status != 'rejected' AND id != ?");
    $stmt->execute([$newCounselorId, $newDate, $appointmentId]);
    $dailyCount = $stmt->fetch()['count'];
    if ($dailyCount >= 9) {
        $stmtName = $db->prepare("SELECT name FROM counselors WHERE user_id = ? LIMIT 1");
        $stmtName->execute([$newCounselorId]);
        $cName = $stmtName->fetch();
        $targetCounselorName = $cName ? $cName['name'] : 'The selected counselor';

        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => "$targetCounselorName is fully booked on $newDate. Please select another counselor or choose a different date."
        ]);
        exit;
    }

    // 3. Overlap check for new counselor on new date/time (excluding this appointment itself)
    $stmt = $db->prepare("
        SELECT COUNT(*) as count FROM appointments 
        WHERE counselor_id = ? 
          AND date = ? 
          AND status != 'rejected'
          AND id != ?
          AND time < ADDTIME(?, '01:00:00') 
          AND ? < ADDTIME(time, '01:00:00')
    ");
    $stmt->execute([$newCounselorId, $newDate, $appointmentId, $newTime, $newTime]);
    $overlapCount = $stmt->fetch()['count'];
    if ($overlapCount > 0) {
        $stmtName = $db->prepare("SELECT name FROM counselors WHERE user_id = ? LIMIT 1");
        $stmtName->execute([$newCounselorId]);
        $cName = $stmtName->fetch();
        $targetCounselorName = $cName ? $cName['name'] : 'The selected counselor';

        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => "$targetCounselorName is already booked during the requested period. Please select a different time slot."
        ]);
        exit;
    }

    $db->beginTransaction();

    // Update appointment
    // We set status back to 'pending' to allow the new counselor (or the same counselor) to explicitly approve it
    $stmt = $db->prepare("UPDATE appointments SET counselor_id = ?, date = ?, time = ?, status = 'pending' WHERE id = ?");
    $stmt->execute([$newCounselorId, $newDate, $newTime, $appointmentId]);

    // Send a system notification to the student
    if ($currentCounselorId !== $newCounselorId) {
        $stmt = $db->prepare("SELECT name FROM counselors WHERE user_id = ? LIMIT 1");
        $stmt->execute([$newCounselorId]);
        $newCounselor = $stmt->fetch();
        $newCounselorName = $newCounselor ? $newCounselor['name'] : 'a new counselor';
        $notificationMsg = "Your session appointment with $counselorName has been reassigned to $newCounselorName and rescheduled to $newDate at $newTime.";
    } else {
        $notificationMsg = "Your session appointment with $counselorName has been rescheduled to $newDate at $newTime.";
    }

    $stmt = $db->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)");
    $stmt->execute([$studentId, $notificationMsg]);

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => "Appointment has been successfully rescheduled."
    ]);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database update error: ' . $e->getMessage()]);
}
