<?php
// =========================================
// EBM_Server — Main Entry Point
// index.php
// =========================================

require_once __DIR__ . '/includes/header.php';

respond('success', 'EBM LMS Server is running! ✅', [
    'server'    => 'EBM_Server',
    'version'   => '1.0.0',
    'endpoints' => [
        'auth'          => '/auth/auth.php?action=login|register|logout|check',
        'modules'       => '/api/modules.php?action=get|single|create|update|delete',
        'users'         => '/api/users.php?action=profile|all|students|update|change_password|delete',
        'grades'        => '/api/grades.php?action=get|save|class',
        'attendance'    => '/api/attendance.php?action=get|mark|summary',
        'announcements' => '/api/announcements.php?action=get|post|delete',
    ]
]);
?>