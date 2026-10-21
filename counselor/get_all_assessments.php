<?php
/**
 * Counselor - Get All Student Assessments
 * Returns all assessment records with student names for the counselor dashboard.
 */

require_once '../config/db.php';
require_once '../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || $_SESSION['role'] !== 'counselor') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized counselor access']);
    exit;
}

$db = get_db_connection();

try {
    // Ensure columns exist
    try {
        $db->exec("ALTER TABLE self_assessments ADD COLUMN assessment_type VARCHAR(50) NOT NULL DEFAULT 'anxiety' AFTER student_id");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE self_assessments ADD COLUMN details JSON NULL AFTER category");
    } catch (Exception $e) {}

    $stmt = $db->query("
        SELECT 
            sa.id, sa.student_id, sa.assessment_type, sa.score, sa.category, sa.details, sa.completed_at,
            p.name AS student_name, p.department, p.student_id AS card_num
        FROM self_assessments sa
        JOIN students_profile p ON sa.student_id = p.user_id
    ");
    $assessments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $maxScores = [
        'anxiety' => 9,
        'depression' => 9,
        'bipolar' => 9,
        'ptsd' => 6,
        'stress' => 6,
        'self_esteem' => 6,
        'combined' => 45
    ];

    foreach ($assessments as &$asmt) {
        $score = intval($asmt['score']);
        $type  = $asmt['assessment_type'];
        $max   = isset($maxScores[$type]) ? $maxScores[$type] : 45;
        $percent = (int)round(($score / $max) * 100);

        if ($percent >= 90) {
            $priority = 'High';
            $priority_rank = 3;
        } else if ($percent >= 50) {
            $priority = 'Medium';
            $priority_rank = 2;
        } else {
            $priority = 'Average';
            $priority_rank = 1;
        }

        $asmt['score_percent'] = $percent;
        $asmt['priority'] = $priority;
        $asmt['priority_rank'] = $priority_rank;
    }
    unset($asmt);

    // Sort: High priority first (rank 3 -> 2 -> 1), then completed_at DESC
    usort($assessments, function($a, $b) {
        if ($a['priority_rank'] !== $b['priority_rank']) {
            return $b['priority_rank'] - $a['priority_rank'];
        }
        return strtotime($b['completed_at']) - strtotime($a['completed_at']);
    });

    echo json_encode([
        'success' => true,
        'data' => $assessments
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
