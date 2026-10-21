<?php
/**
 * Session Security & RBAC Configuration
 * Ensures pages are protected and implements 15-minute session timeouts.
 */

// Establish secure cookie settings
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Strict');
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', 1);
}

// Start session if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Define session duration (15 minutes = 900 seconds)
define('SESSION_TIMEOUT', 900);

/**
 * Handle Session Expiry & Access Logging
 */
if (isset($_SESSION['user_id'])) {
    // Check if session has timed out
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
        // Session expired
        session_unset();
        session_destroy();
        
        // Restart empty session for subsequent use
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    } else {
        // Update last activity timestamp
        $_SESSION['last_activity'] = time();
    }
}

/**
 * Check if the request is an API request (expects JSON)
 */
function is_api_request() {
    return (
        (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
        (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) ||
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest')
    );
}

/**
 * Send uniform JSON responses
 */
function send_json_response($success, $message, $data = null, $http_code = 200) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($http_code);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

/**
 * Enforce role authorization
 * @param array $allowed_roles Roles allowed to access the page (e.g. ['student', 'admin'])
 */
function enforce_auth($allowed_roles = []) {
    if (!isset($_SESSION['user_id'])) {
        send_json_response(false, 'Authentication required. Please log in.', null, 401);
    }
    
    if (!empty($allowed_roles) && !in_array($_SESSION['role'], $allowed_roles)) {
        send_json_response(false, 'Access denied. Unauthorized role.', null, 403);
    }
}

/**
 * Check if a user session is active
 */
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

/**
 * Sanitize input helper for forms
 */
function sanitize_input($data) {
    if (is_array($data)) {
        return array_map('sanitize_input', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Parse JSON Request Body (from fetch POSTs)
 */
function get_json_input() {
    $raw_input = file_get_contents('php://input');
    $decoded = json_decode($raw_input, true);
    return is_array($decoded) ? sanitize_input($decoded) : [];
}
