<?php
// =========================================
// EBM_Server — Modules API
// api/modules.php
// Handles: get, create, update, delete modules
// =========================================

session_start();

require_once __DIR__ . '/../includes/header.php';

require_once __DIR__ . '/../config/database.php';

requireLogin();

$action = isset($_GET['action']) ? clean($_GET['action']) : 'get';
$conn   = getConnection();

switch ($action) {

    // ---- GET MODULES (for logged-in student or teacher) ----
    case 'get':
        $role = $_SESSION['user_role'];
        $id   = $_SESSION['user_id'];

        if ($role === 'student') {
            // Get only modules the student is enrolled in
            $stmt = $conn->prepare("
                SELECT m.*, CONCAT(u.first_name, ' ', u.last_name) AS teacher_name,
                    COUNT(DISTINCT l.id) AS total_lessons,
                    COUNT(DISTINCT lp.id) AS completed_lessons
                FROM modules m
                JOIN enrollments e ON e.module_id = m.id AND e.student_id = ?
                JOIN users u ON u.id = m.teacher_id
                LEFT JOIN lessons l ON l.module_id = m.id
                LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.student_id = ? AND lp.completed = 1
                WHERE m.status = 'active'
                GROUP BY m.id
            ");
            $stmt->bind_param("ii", $id, $id);

        } elseif ($role === 'teacher') {
            // Get modules owned by this teacher
            $stmt = $conn->prepare("
                SELECT m.*, COUNT(DISTINCT e.student_id) AS enrolled_students,
                    COUNT(DISTINCT l.id) AS total_lessons
                FROM modules m
                LEFT JOIN enrollments e ON e.module_id = m.id
                LEFT JOIN lessons l ON l.module_id = m.id
                WHERE m.teacher_id = ?
                GROUP BY m.id
            ");
            $stmt->bind_param("i", $id);

        } else {
            // Admin gets all modules
            $stmt = $conn->prepare("
                SELECT m.*, CONCAT(u.first_name, ' ', u.last_name) AS teacher_name,
                    COUNT(DISTINCT e.student_id) AS enrolled_students
                FROM modules m
                JOIN users u ON u.id = m.teacher_id
                LEFT JOIN enrollments e ON e.module_id = m.id
                GROUP BY m.id
            ");
        }

        $stmt->execute();
        $modules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        respond('success', 'Modules fetched.', $modules);
        break;

    // ---- GET SINGLE MODULE WITH LESSONS ----
    case 'single':
        $module_id = intval($_GET['id'] ?? 0);

        $stmt = $conn->prepare("
            SELECT m.*, CONCAT(u.first_name, ' ', u.last_name) AS teacher_name
            FROM modules m
            JOIN users u ON u.id = m.teacher_id
            WHERE m.id = ?
        ");
        $stmt->bind_param("i", $module_id);
        $stmt->execute();
        $module = $stmt->get_result()->fetch_assoc();

        if (!$module) respond('error', 'Module not found.');

        // Get lessons
        $lesson_stmt = $conn->prepare("SELECT * FROM lessons WHERE module_id = ? ORDER BY order_num");
        $lesson_stmt->bind_param("i", $module_id);
        $lesson_stmt->execute();
        $module['lessons'] = $lesson_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        respond('success', 'Module fetched.', $module);
        break;

    // ---- CREATE MODULE (teacher/admin only) ----
    case 'create':
        requireRole('teacher');

        $input       = json_decode(file_get_contents('php://input'), true);
        $title       = clean($input['title'] ?? '');
        $description = clean($input['description'] ?? '');
        $emoji       = clean($input['emoji'] ?? '📚');
        $color       = clean($input['color'] ?? 'blue');
        $teacher_id  = $_SESSION['user_id'];

        if (empty($title)) respond('error', 'Module title is required.');

        $stmt = $conn->prepare("INSERT INTO modules (title, description, teacher_id, emoji, color) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssiss", $title, $description, $teacher_id, $emoji, $color);

        if ($stmt->execute()) {
            respond('success', 'Module created!', ['id' => $conn->insert_id]);
        } else {
            respond('error', 'Failed to create module.');
        }
        break;

    // ---- UPDATE MODULE ----
    case 'update':
        requireRole('teacher');

        $input       = json_decode(file_get_contents('php://input'), true);
        $module_id   = intval($input['id'] ?? 0);
        $title       = clean($input['title'] ?? '');
        $description = clean($input['description'] ?? '');

        $stmt = $conn->prepare("UPDATE modules SET title = ?, description = ? WHERE id = ? AND teacher_id = ?");
        $stmt->bind_param("ssii", $title, $description, $module_id, $_SESSION['user_id']);

        if ($stmt->execute()) {
            respond('success', 'Module updated!');
        } else {
            respond('error', 'Failed to update module.');
        }
        break;

    // ---- DELETE MODULE ----
    case 'delete':
        requireRole('teacher');

        $module_id = intval($_GET['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM modules WHERE id = ? AND teacher_id = ?");
        $stmt->bind_param("ii", $module_id, $_SESSION['user_id']);

        if ($stmt->execute()) {
            respond('success', 'Module deleted.');
        } else {
            respond('error', 'Failed to delete module.');
        }
        break;

    default:
        respond('error', 'Invalid action.');
}
?>