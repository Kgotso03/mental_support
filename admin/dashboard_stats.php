<?php
/**
 * Administrator Dashboard Performance Statistics API Endpoint
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

try {
    $stats = [];

    // Total Students
    $stmt = $db->query("SELECT COUNT(*) AS total FROM users WHERE role = 'student'");
    $studentUsers = $stmt ? intval($stmt->fetch()['total']) : 0;
    $stmt = $db->query("SELECT COUNT(*) AS total FROM students_profile");
    $studentProfiles = $stmt ? intval($stmt->fetch()['total']) : 0;
    $stats['students'] = max($studentUsers, $studentProfiles);

    // Total Counselors
    $stmt = $db->query("SELECT COUNT(*) AS total FROM users WHERE role = 'counselor'");
    $counselorUsers = $stmt ? intval($stmt->fetch()['total']) : 0;
    $stmt = $db->query("SELECT COUNT(*) AS total FROM counselors");
    $counselorProfiles = $stmt ? intval($stmt->fetch()['total']) : 0;
    $stats['counselors'] = max($counselorUsers, $counselorProfiles);

    // Total Lecturers
    $stmt = $db->query("SELECT COUNT(*) AS total FROM users WHERE role = 'lecturer'");
    $lecturerUsers = $stmt ? intval($stmt->fetch()['total']) : 0;
    $stmt = $db->query("SELECT COUNT(*) AS total FROM lecturers");
    $lecturerProfiles = $stmt ? intval($stmt->fetch()['total']) : 0;
    $stats['lecturers'] = max($lecturerUsers, $lecturerProfiles);

    // Total Therapy Sessions
    $stmt = $db->query("SELECT COUNT(*) AS total FROM appointments");
    $stats['appointments'] = $stmt ? intval($stmt->fetch()['total']) : 0;

    // Total Shared Stories
    $stmt = $db->query("SELECT COUNT(*) AS total FROM shared_stories");
    $stats['stories'] = $stmt ? intval($stmt->fetch()['total']) : 0;

    // Total Assessments Taken
    $stmt = $db->query("SELECT COUNT(*) AS total FROM self_assessments");
    $stats['assessments'] = $stmt ? intval($stmt->fetch()['total']) : 0;

    echo json_encode([
        'success' => true,
        'data' => [
            'counts' => [
                'students'     => $stats['students'],
                'counselors'   => $stats['counselors'],
                'lecturers'    => $stats['lecturers'],
                'appointments' => $stats['appointments'],
                'stories'      => $stats['stories'],
                'assessments'  => $stats['assessments'],
            ]
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database statistics compilation error: ' . $e->getMessage()]);
}
