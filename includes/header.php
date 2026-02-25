<?php
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');

function respond($status = 'error', $message = '', $data = null) {
    ob_end_clean(); // Discard any stray output before sending JSON
    $response = ['status' => $status, 'message' => $message];
    if ($data !== null) {
        $response['data'] = $data;
    }
    http_response_code($status === 'success' ? 200 : 400);
    echo json_encode($response);
    exit();
}

function clean($input) {
    if (is_array($input)) return array_map('clean', $input);
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        respond('error', 'Not logged in. Please authenticate first.');
    }
}

function requireRole($required_role) {
    requireLogin();
    $user_role = $_SESSION['user_role'] ?? '';
    if ($user_role === 'admin') return true;
    if ($user_role !== $required_role) {
        respond('error', 'Insufficient permissions. Required role: ' . $required_role);
    }
    return true;
}
?>