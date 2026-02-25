<?php
header('Content-Type: application/json');
$host = getenv('MYSQLHOST');
$port = getenv('MYSQLPORT');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');
$db   = getenv('MYSQLDATABASE');
echo json_encode([
    'status' => 'ok',
    'env' => [
        'MYSQLHOST'     => $host ?: 'MISSING',
        'MYSQLPORT'     => $port ?: 'MISSING',
        'MYSQLUSER'     => $user ?: 'MISSING',
        'MYSQLPASSWORD' => $pass ? '***set***' : 'MISSING',
        'MYSQLDATABASE' => $db   ?: 'MISSING',
    ]
]);
