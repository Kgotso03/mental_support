<?php
/**
 * Counselor Appointment Status Update API Endpoint
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
$status = isset($input['status']) ? sanitize_input($input['status']) : '';
$recommendGroup = isset($input['recommend_group']) ? (bool)$input['recommend_group'] : false;

$validStatuses = ['accepted', 'completed'];
if (empty($appointmentId) || !in_array($status, $validStatuses)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid appointment ID or status value']);
    exit;
}

$counselorId = $_SESSION['user_id'];
$counselorName = $_SESSION['name'];
$db = get_db_connection();

try {
    // Verify that the appointment exists and is assigned to this counselor
    $stmt = $db->prepare("SELECT student_id, date, time FROM appointments WHERE id = ? AND counselor_id = ? LIMIT 1");
    $stmt->execute([$appointmentId, $counselorId]);
    $appointment = $stmt->fetch();

    if (!$appointment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Session appointment not found or access denied']);
        exit;
    }

    $studentId = $appointment['student_id'];
    $date = $appointment['date'];
    $time = $appointment['time'];

    // Fetch Student Email and Name for email notification
    $stmtStudent = $db->prepare("SELECT u.email, sp.name FROM users u JOIN students_profile sp ON u.id = sp.user_id WHERE u.id = ? LIMIT 1");
    $stmtStudent->execute([$studentId]);
    $studentInfo = $stmtStudent->fetch();
    $studentEmail = $studentInfo ? $studentInfo['email'] : '';
    $studentProfileName = $studentInfo ? $studentInfo['name'] : 'Student';

    $db->beginTransaction();

    // Update status
    $stmt = $db->prepare("UPDATE appointments SET status = ? WHERE id = ?");
    $stmt->execute([$status, $appointmentId]);

    // If marked completed, recommend for group discussion (update student's profile) if chosen
    if ($status === 'completed') {

        if ($recommendGroup) {
            $stmt = $db->prepare("UPDATE students_profile SET group_recommended = 1, discussion_choice = 'none' WHERE user_id = ?");
            $stmt->execute([$studentId]);

            // Send recommendation notification to student
            $notifMsg = "Your counselor recommends that you join a discussion group. Please book a group discussion session.";
            $stmt = $db->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)");
            $stmt->execute([$studentId, $notifMsg]);
        }
    }

    // Send a system notification to the student
    $notificationMsg = "Your session appointment with Counselor $counselorName on $date at $time has been updated to '$status'.";
    $stmt = $db->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)");
    $stmt->execute([$studentId, $notificationMsg]);

    $db->commit();

    // Send Appointment Status Update Email
    if (!empty($studentEmail)) {
        require_once '../config/mail.php';
        
        $subject = "Appointment Status Update: Session with Counselor $counselorName";
        
        $statusColor = ($status === 'completed' ? '#0d9488' : ($status === 'rejected' ? '#dc2626' : '#2563eb'));
        $statusMessage = "Your consultation appointment scheduled for <strong>$date</strong> at <strong>$time</strong> has been updated to <strong style='color: $statusColor;'>" . ucfirst($status) . "</strong> by Counselor $counselorName.";
        
        $extraContent = "";
        if ($status === 'completed') {
            $extraContent = "<p>Thank you for attending the session. We hope you found it helpful.</p>";
            if ($recommendGroup) {
                $extraContent .= "
                    <div style='background-color: #f0fdfa; border-left: 4px solid #0f766e; padding: 12px; margin: 15px 0; border-radius: 4px;'>
                        <strong style='color: #0f766e;'>Peer Support Recommendation:</strong><br/>
                        Counselor $counselorName has recommended that you participate in a peer support discussion group. 
                        Please log in to your dashboard to book a group discussion session.
                    </div>
                ";
            }
        } elseif ($status === 'accepted') {
            $extraContent = "<p>Your booking request has been accepted. Please log in to your student portal at the scheduled time to launch the consultation.</p>";
        }
        
        $bodyHtml = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px;'>
                <h2 style='color: #0f766e;'>Hello " . htmlspecialchars($studentProfileName) . ",</h2>
                <p>$statusMessage</p>
                $extraContent
                <p>Log in to your Student Dashboard to view your full appointment history or book future sessions.</p>
                <hr style='border: 0; border-top: 1px solid #e2e8f0; margin: 20px 0;' />
                <p style='font-size: 0.875rem; color: #64748b;'>This is an automated email notification. Please do not reply directly to this message.</p>
            </div>
        ";
        
        send_email($studentEmail, $studentProfileName, $subject, $bodyHtml);
    }

    echo json_encode([
        'success' => true,
        'message' => "Appointment has been successfully marked as $status."
    ]);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database update error: ' . $e->getMessage()]);
}
