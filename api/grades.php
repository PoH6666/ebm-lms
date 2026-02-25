<?php
// =========================================
// EBM_Server — Grades API
// api/grades.php
// =========================================

session_start();


require_once __DIR__ . '/../config/database.php';

requireLogin();

$action = isset($_GET['action']) ? clean($_GET['action']) : 'get';
$conn   = getConnection();

switch ($action) {

    // ---- GET MY GRADES (student) ----
    case 'get':
        $stmt = $conn->prepare("
            SELECT g.*, m.title AS module_title, m.emoji
            FROM grades g
            JOIN modules m ON m.id = g.module_id
            WHERE g.student_id = ?
            ORDER BY g.graded_at DESC
        ");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $grades = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        respond('success', 'Grades fetched.', $grades);
        break;

    // ---- ADD/UPDATE GRADE (teacher/admin) ----
    case 'save':
        requireRole('teacher');

        $input      = json_decode(file_get_contents('php://input'), true);
        $student_id = intval($input['student_id'] ?? 0);
        $module_id  = intval($input['module_id'] ?? 0);
        $score      = floatval($input['score'] ?? 0);
        $remarks    = clean($input['remarks'] ?? '');

        if ($score < 0 || $score > 100) {
            respond('error', 'Score must be between 0 and 100.');
        }

        // Insert or update
        $stmt = $conn->prepare("
            INSERT INTO grades (student_id, module_id, score, remarks)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE score = VALUES(score), remarks = VALUES(remarks), graded_at = NOW()
        ");
        $stmt->bind_param("iids", $student_id, $module_id, $score, $remarks);

        if ($stmt->execute()) {
            respond('success', 'Grade saved!');
        } else {
            respond('error', 'Failed to save grade.');
        }
        break;

    // ---- GET CLASS GRADES (teacher) ----
    case 'class':
        requireRole('teacher');
        $module_id = intval($_GET['module_id'] ?? 0);

        $stmt = $conn->prepare("
            SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) AS student_name,
                u.school_id, g.score, g.remarks, g.graded_at
            FROM enrollments e
            JOIN users u ON u.id = e.student_id
            LEFT JOIN grades g ON g.student_id = e.student_id AND g.module_id = e.module_id
            WHERE e.module_id = ?
            ORDER BY u.last_name
        ");
        $stmt->bind_param("i", $module_id);
        $stmt->execute();
        $grades = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        respond('success', 'Class grades fetched.', $grades);
        break;

    default:
        respond('error', 'Invalid action.');
}
?>