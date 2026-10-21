<?php
/**
 * WebRTC Signaling API
 *
 * POST  body: {room_token, signal_type, payload}
 *       — store a signal (offer / answer / ice candidate)
 *
 * GET   ?room_token=...&role=...
 *       — fetch unconsumed signals sent by the OTHER role, mark them consumed
 *
 * DELETE ?room_token=...
 *       — purge all signals for a room (on hang-up)
 */

require_once '../config/db.php';
require_once '../config/session.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, ngrok-skip-browser-warning, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$db   = get_db_connection();
$rawRole = $_SESSION['role'] ?? 'student';
$role    = ($rawRole === 'counselor' || $rawRole === 'admin') ? 'counselor' : 'student';

// ── DELETE: purge signals for a room ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $token = $_GET['room_token'] ?? '';
    if (!$token) {
        $body  = json_decode(file_get_contents('php://input'), true);
        $token = $body['room_token'] ?? '';
    }
    if ($token) {
        $db->prepare("DELETE FROM webrtc_signals WHERE room_token = ?")->execute([$token]);
    }
    echo json_encode(['success' => true]);
    exit;
}

// ── GET: poll for new signals ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = trim($_GET['room_token'] ?? '');
    if (!$token) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'room_token required']);
        exit;
    }

    // Fetch signals sent by any other participant (not by us)
    $stmt = $db->prepare("
        SELECT id, signal_type, payload
        FROM webrtc_signals
        WHERE room_token = ?
          AND sender_role != ?
          AND consumed = 0
        ORDER BY id ASC
        LIMIT 20
    ");
    $stmt->execute([$token, $role]);
    $signals = $stmt->fetchAll();

    if (!empty($signals)) {
        // Mark them consumed so they won't be returned again
        $ids = implode(',', array_map(fn($s) => intval($s['id']), $signals));
        $db->exec("UPDATE webrtc_signals SET consumed = 1 WHERE id IN ($ids)");
    }

    // Decode payloads back to objects for JS
    $result = array_map(function($s) {
        return [
            'id'          => $s['id'],
            'signal_type' => $s['signal_type'],
            'payload'     => json_decode($s['payload'], true)
        ];
    }, $signals);

    echo json_encode(['success' => true, 'signals' => $result]);
    exit;
}

// ── POST: send a signal ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) $body = $_POST;

    $token      = trim($body['room_token']   ?? '');
    $signalType = trim($body['signal_type']  ?? '');
    $payload    = $body['payload']            ?? null;

    if (!$token || !$signalType || $payload === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'room_token, signal_type and payload are required']);
        exit;
    }

    $validTypes = ['offer', 'answer', 'ice'];
    if (!in_array($signalType, $validTypes)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid signal_type']);
        exit;
    }

    // For offer/answer: replace any previous unconsumed one (only one active offer/answer at a time)
    if ($signalType === 'offer' || $signalType === 'answer') {
        $del = $db->prepare("
            DELETE FROM webrtc_signals
            WHERE room_token = ? AND sender_role = ? AND signal_type = ? AND consumed = 0
        ");
        $del->execute([$token, $role, $signalType]);
    }

    // Clean up old consumed signals and old ICE to keep table lean
    try {
        $db->prepare("
            DELETE FROM webrtc_signals
            WHERE room_token = ? AND consumed = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ")->execute([$token]);
    } catch (Exception $e) {}

    $ins = $db->prepare("
        INSERT INTO webrtc_signals (room_token, sender_role, signal_type, payload, consumed)
        VALUES (?, ?, ?, ?, 0)
    ");
    $ins->execute([$token, $role, $signalType, json_encode($payload)]);

    echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
