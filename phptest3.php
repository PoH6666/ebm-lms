<?php
header('Content-Type: application/json');
$steps = [];

// Step 1
$steps[] = 'ini_set OK';
ini_set('display_errors', '0');
error_reporting(0);

// Step 2
ob_start();
$steps[] = 'ob_start OK';

// Step 3
try {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $steps[] = 'session_start OK';
} catch (Exception $e) {
    $steps[] = 'session_start FAILED: ' . $e->getMessage();
}

// Step 4 - this is the critical one
try {
    require_once __DIR__ . '/../config/database.php';
    $steps[] = 'require database.php OK';
} catch (Throwable $e) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'step' => 'require_failed', 'steps' => $steps, 'error' => $e->getMessage()]);
    exit();
}

ob_end_clean();

// Step 5
try {
    $conn = getConnection();
    $steps[] = 'getConnection() OK';
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'step' => 'getConnection_failed', 'steps' => $steps, 'error' => $e->getMessage()]);
    exit();
}

echo json_encode(['status' => 'success', 'steps' => $steps, 'conn_alive' => (bool)$conn]);
