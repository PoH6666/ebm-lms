<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('DB_HOST', getenv('MYSQLHOST'));
define('DB_PORT', getenv('MYSQLPORT') ?: 3306);
define('DB_USER', getenv('MYSQLUSER'));
define('DB_PASS', getenv('MYSQLPASSWORD'));
define('DB_NAME', getenv('MYSQLDATABASE'));

function getConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode([
            'status'  => 'error',
            'message' => 'Database connection failed: ' . $conn->connect_error
        ]);
        exit();
    }
    @$conn->set_charset('utf8');
    return $conn;
}
?>
