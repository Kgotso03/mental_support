<?php
/**
 * Student Counseling Sessions Fetching API Endpoint
 */

require_once '../config/db.php';
require_once '../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || $_SESSION['role'] !== 'student') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized student access']);
    exit;
}

$studentId = $_SESSION['user_id'];
$action = isset($_GET['action']) ? sanitize_input($_GET['action']) : 'list';
$db = get_db_connection();

try {
    if ($action === 'list_counselors') {
        // Fetch all verified active counselors for dropdown selection (optionally filtered by specialty)
        $specialty = isset($_GET['specialty']) ? trim(sanitize_input($_GET['specialty'])) : '';
        if (!empty($specialty)) {
            $stmt = $db->prepare("SELECT user_id, name, specialty, bio, status FROM counselors WHERE status = 'active' AND specialty LIKE :spec ORDER BY name ASC");
            $stmt->execute([':spec' => "%$specialty%"]);
        } else {
            $stmt = $db->query("SELECT user_id, name, specialty, bio, status FROM counselors WHERE status = 'active' ORDER BY name ASC");
        }
        $counselors = $stmt->fetchAll();

        // Fetch current student's group discussion recommendation status and choice
        $stmt = $db->prepare("SELECT group_recommended, discussion_choice FROM students_profile WHERE user_id = ? LIMIT 1");
        $stmt->execute([$studentId]);
        $profile = $stmt->fetch();
        $groupRecommended = $profile ? intval($profile['group_recommended']) : 0;
        $discussionChoice = $profile ? $profile['discussion_choice'] : 'none';

        // Check if student has completed at least one self-assessment
        $stmtAsmt = $db->prepare("SELECT COUNT(*) as count FROM self_assessments WHERE student_id = ?");
        $stmtAsmt->execute([$studentId]);
        $hasAssessment = ($stmtAsmt->fetch()['count'] > 0);

        echo json_encode([
            'success' => true,
            'data' => [
                'counselors' => $counselors,
                'group_recommended' => $groupRecommended,
                'discussion_choice' => $discussionChoice,
                'has_assessment' => $hasAssessment
            ]
        ]);
    } else if ($action === 'get_booked_slots') {
        $counselorId = isset($_GET['counselor_id']) ? intval($_GET['counselor_id']) : 0;
        $date = isset($_GET['date']) ? trim(sanitize_input($_GET['date'])) : '';

        if (!$counselorId || !$date) {
            echo json_encode(['success' => true, 'booked_slots' => []]);
            exit;
        }

        $stmt = $db->prepare("
            SELECT time FROM appointments 
            WHERE counselor_id = ? AND date = ? AND status != 'rejected'
        ");
        $stmt->execute([$counselorId, $date]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $bookedSlots = [];
        foreach ($rows as $row) {
            if (!empty($row['time'])) {
                $bookedSlots[] = date('H:i', strtotime($row['time']));
            }
        }

        echo json_encode([
            'success' => true,
            'booked_slots' => array_values(array_unique($bookedSlots))
        ]);
        exit;
    } else {
        // Fetch all bookings for this student with counselor profile names
        $stmt = $db->prepare("
            SELECT a.id, a.date, a.time, a.status, a.meeting_link, a.session_type, c.name AS counselor_name, c.specialty 
            FROM appointments a 
            JOIN counselors c ON a.counselor_id = c.user_id 
            WHERE a.student_id = ? 
            ORDER BY a.date DESC, a.time DESC
        ");
        $stmt->execute([$studentId]);
        $appointments = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'data' => $appointments
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Query error: ' . $e->getMessage()]);
}
