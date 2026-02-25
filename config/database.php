<?php
// =========================================
// EBM_Server — Database Configuration
// config/database.php
// =========================================

define('DB_HOST', 'sql303.infinityfree.com');
define('DB_USER', 'if0_41240099');
define('DB_PASS', 'your_account_password');  // Replace with your InfinityFree password
define('DB_NAME', 'if0_41240099_ebm_lms');

function getConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode([
            'status'  => 'error',
            'message' => 'Database connection failed: ' . $conn->connect_error
        ]);
        exit();
    }

    $conn->set_charset('utf8');
    return $conn;
}
?>