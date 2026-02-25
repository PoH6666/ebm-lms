<?php
// =========================================
// EBM_Server — Attendance API
// api/attendance.php
// =========================================

session_start();


require_once __DIR__ . '/../config/database.php';

requireLogin();

$action = isset($_GET['action']) ? clean($_GET['action']) : 'get';
$conn   = getConnection();

switch ($action) {

    // ---- GET MY ATTENDANCE (student) ----
    case 'get':
        $stmt = $conn->prepare("
            SELECT a.*, m.title AS module_title, m.emoji
            FROM attendance a
            JOIN modules m ON m.id = a.module_id
            WHERE a.student_id = ?
            ORDER BY a.date DESC
        ");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        respond('success', 'Attendance fetched.', $records);
        break;

    // ---- MARK ATTENDANCE (teacher/admin) ----
    case 'mark':
        requireRole('teacher');

        $input      = json_decode(file_get_contents('php://input'), true);
        $student_id = intval($input['student_id'] ?? 0);
        $module_id  = intval($input['module_id'] ?? 0);
        $date       = clean($input['date'] ?? date('Y-m-d'));
        $status     = clean($input['status'] ?? 'present');
        $noted_by   = $_SESSION['user_id'];

        $valid = ['present', 'absent', 'late', 'excused'];
        if (!in_array($status, $valid)) {
            respond('error', 'Invalid status.');
        }

        $stmt = $conn->prepare("
            INSERT INTO attendance (student_id, module_id, date, status, noted_by)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE status = VALUES(status), noted_by = VALUES(noted_by)
        ");
        $stmt->bind_param("iissi", $student_id, $module_id, $date, $status, $noted_by);

        if ($stmt->execute()) {
            respond('success', 'Attendance marked!');
        } else {
            respond('error', 'Failed to mark attendance.');
        }
        break;

    // ---- GET ATTENDANCE SUMMARY ----
    case 'summary':
        $student_id = intval($_GET['student_id'] ?? $_SESSION['user_id']);

        $stmt = $conn->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(status = 'present') AS present,
                SUM(status = 'absent') AS absent,
                SUM(status = 'late') AS late,
                SUM(status = 'excused') AS excused,
                ROUND((SUM(status = 'present') / COUNT(*)) * 100, 1) AS rate
            FROM attendance WHERE student_id = ?
        ");
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $summary = $stmt->get_result()->fetch_assoc();
        respond('success', 'Summary fetched.', $summary);
        break;

    default:
        respond('error', 'Invalid action.');
}
?>