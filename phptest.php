<?php
header('Content-Type: application/json');
echo json_encode(['status' => 'ok', 'message' => 'PHP is working!', 'php_version' => PHP_VERSION]);
