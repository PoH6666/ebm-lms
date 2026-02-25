<?php
header('Content-Type: application/json');

$result = [];

// Check mysqli exists
$result['mysqli_available'] = extension_loaded('mysqli');
$result['pdo_mysql_available'] = extension_loaded('pdo_mysql');

// Check all possible env var names
$envVars = [
    'MYSQLHOST', 'MYSQLUSER', 'MYSQLPASSWORD', 'MYSQLDATABASE', 'MYSQLPORT',
    'DB_HOST', 'DB_USER', 'DB_PASSWORD', 'DB_NAME', 'DB_PORT',
    'MYSQL_HOST', 'MYSQL_USER', 'MYSQL_PASSWORD', 'MYSQL_DATABASE', 'MYSQL_PORT',
    'DATABASE_URL', 'MYSQL_URL', 'MYSQL_PUBLIC_URL'
];

$found = [];
foreach ($envVars as $var) {
    $val = getenv($var);
    if ($val !== false) {
        // Mask password
        if (strpos($var, 'PASSWORD') !== false || strpos($var, 'URL') !== false) {
            $found[$var] = strlen($val) > 0 ? '***SET (len=' . strlen($val) . ')' : '(empty)';
        } else {
            $found[$var] = $val;
        }
    }
}
$result['env_vars_found'] = $found;

// Try to connect
if (extension_loaded('mysqli')) {
    $host = getenv('MYSQLHOST') ?: getenv('DB_HOST') ?: getenv('MYSQL_HOST') ?: '';
    $user = getenv('MYSQLUSER') ?: getenv('DB_USER') ?: getenv('MYSQL_USER') ?: '';
    $pass = getenv('MYSQLPASSWORD') ?: getenv('DB_PASSWORD') ?: getenv('MYSQL_PASSWORD') ?: '';
    $db   = getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: getenv('MYSQL_DATABASE') ?: '';
    $port = (int)(getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: getenv('MYSQL_PORT') ?: 3306);

    $result['connect_params'] = [
        'host' => $host,
        'user' => $user,
        'pass' => $pass ? '***SET***' : '(empty)',
        'db'   => $db,
        'port' => $port
    ];

    if ($host && $user && $db) {
        $conn = @new mysqli($host, $user, $pass, $db, $port);
        if ($conn->connect_error) {
            $result['connect_status'] = 'FAILED: ' . $conn->connect_error;
        } else {
            $result['connect_status'] = 'SUCCESS';
            // Check subjects table
            $r = $conn->query("SHOW TABLES LIKE 'subjects'");
            $result['subjects_table_exists'] = $r && $r->num_rows > 0;
            if ($result['subjects_table_exists']) {
                $cols = $conn->query("SHOW COLUMNS FROM subjects");
                $colNames = [];
                while ($row = $cols->fetch_assoc()) $colNames[] = $row['Field'];
                $result['subjects_columns'] = $colNames;
            }
            $conn->close();
        }
    } else {
        $result['connect_status'] = 'SKIPPED - missing host/user/db env vars';
    }
} else {
    $result['connect_status'] = 'SKIPPED - mysqli not available';
    
    // Try PDO as fallback
    if (extension_loaded('pdo_mysql')) {
        $result['pdo_note'] = 'PDO MySQL is available as fallback';
    }
}

echo json_encode($result, JSON_PRETTY_PRINT);
