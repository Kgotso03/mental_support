<?php
/**
 * Counselor Bookings Fetching API Endpoint
 */

require_once '../config/db.php';
require_once '../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || $_SESSION['role'] !== 'counselor') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized counselor access']);
    exit;
}

$action = isset($_GET['action']) ? sanitize_input($_GET['action']) : 'list';
$counselorId = $_SESSION['user_id'];
$db = get_db_connection();

try {
    if ($action === 'list_counselors') {
        // Fetch all verified active counselors for dropdown selection
        $stmt = $db->query("SELECT user_id, name, specialty, bio, status FROM counselors WHERE status = 'active' ORDER BY name ASC");
        $counselors = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'data' => [
                'counselors' => $counselors
            ]
        ]);
    } else {
        $stmt = $db->prepare("
            SELECT a.id, a.student_id, a.date, a.time, a.status, a.meeting_link, a.session_type, a.is_urgent, a.urgency_disorder,
                   a.counselor_progress_note,
                   p.name AS student_name, p.student_id AS student_card_num,
                   sa.score AS latest_score, sa.assessment_type AS latest_asmt_type
            FROM appointments a 
            JOIN students_profile p ON a.student_id = p.user_id 
            LEFT JOIN (
                SELECT s1.student_id, s1.score, s1.assessment_type
                FROM self_assessments s1
                INNER JOIN (
                    SELECT student_id, MAX(id) AS max_id
                    FROM self_assessments
                    GROUP BY student_id
                ) s2 ON s1.id = s2.max_id
            ) sa ON a.student_id = sa.student_id
            WHERE a.counselor_id = ?
        ");
        $stmt->execute([$counselorId]);
        $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $maxScores = [
            'anxiety' => 9,
            'depression' => 9,
            'bipolar' => 9,
            'ptsd' => 6,
            'stress' => 6,
            'self_esteem' => 6,
            'combined' => 45
        ];

        foreach ($appointments as &$apt) {
            $score = (isset($apt['latest_score']) && $apt['latest_score'] !== null) ? intval($apt['latest_score']) : null;
            $type  = isset($apt['latest_asmt_type']) ? $apt['latest_asmt_type'] : 'combined';
            $max   = isset($maxScores[$type]) ? $maxScores[$type] : 45;

            if ($score !== null && $max > 0) {
                $percent = (int)round(($score / $max) * 100);
            } else {
                $percent = 0;
            }

            // Priority Classification:
            // High Priority: Score >= 90% (or is_urgent == 1)
            // Medium Priority: 50% to < 90%
            // Low/Average Priority: < 50%
            if ($percent >= 90 || intval($apt['is_urgent']) === 1) {
                $priority = 'High';
                $priority_rank = 3;
            } else if ($percent >= 50) {
                $priority = 'Medium';
                $priority_rank = 2;
            } else {
                $priority = 'Average';
                $priority_rank = 1;
            }

            $apt['score_percent'] = $percent;
            $apt['priority'] = $priority;
            $apt['priority_rank'] = $priority_rank;
        }
        unset($apt);

        // Sort: High priority first (rank 3 -> 2 -> 1), then FCFS by requested date & time ASC (earliest first)
        usort($appointments, function($a, $b) {
            if ($a['priority_rank'] !== $b['priority_rank']) {
                return $b['priority_rank'] - $a['priority_rank'];
            }
            $tA = strtotime($a['date'] . ' ' . $a['time']);
            $tB = strtotime($b['date'] . ' ' . $b['time']);
            return $tA - $tB;
        });

        echo json_encode([
            'success' => true,
            'data' => $appointments
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
