<?php
ob_start();
define('DB_HOST', 'mysql.railway.internal');
define('DB_PORT', 3306);
define('DB_USER', 'root');
define('DB_PASS', 'qErxeJhOQobMRAaKZBqhKGnxQIjvWzxh');
define('DB_NAME', 'railway');
function getConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    if ($conn->connect_error) {
        ob_end_clean();
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
