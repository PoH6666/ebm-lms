<?php
// =========================================
// EBM_Server — Teacher API
// api/teacher.php
// =========================================

ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Path: api/teacher.php → go up one level to reach config/
require_once __DIR__ . '/../config/database.php';

// ── Helpers ──────────────────────────────────────────────────────
function respond($status, $message, $data = null) {
    ob_end_clean();
    http_response_code($status === 'success' ? 200 : 400);
    $res = ['status' => $status, 'message' => $message];
    if ($data !== null) $res['data'] = $data;
    echo json_encode($res);
    exit();
}

function clean($v) {
    if (is_array($v)) return array_map('clean', $v);
    return htmlspecialchars(trim($v), ENT_QUOTES, 'UTF-8');
}

function p($key, $default = '') {
    if (isset($_GET[$key])  && $_GET[$key]  !== '') return clean($_GET[$key]);
    if (isset($_POST[$key]) && $_POST[$key] !== '') return clean($_POST[$key]);
    return $default;
}

function pRaw($key, $default = '') {
    if (isset($_GET[$key])  && $_GET[$key]  !== '') return $_GET[$key];
    if (isset($_POST[$key]) && $_POST[$key] !== '') return $_POST[$key];
    return $default;
}

// ── Get params ───────────────────────────────────────────────────
$action     = p('action');
$teacher_id = (int) p('teacher_id');

if (!$teacher_id) {
    respond('error', 'teacher_id is required.');
}

// ── DB connection ────────────────────────────────────────────────
$conn = getConnection();

// ── Ensure assignments table has all required columns ────────────
// This runs silently — safe to call every request
$conn->query("ALTER TABLE assignments ADD COLUMN IF NOT EXISTS type VARCHAR(20) DEFAULT 'quiz'");
$conn->query("ALTER TABLE assignments ADD COLUMN IF NOT EXISTS time_limit INT DEFAULT 30");
$conn->query("ALTER TABLE assignments ADD COLUMN IF NOT EXISTS passing_score INT DEFAULT 75");
$conn->query("ALTER TABLE assignments ADD COLUMN IF NOT EXISTS instructions TEXT");
$conn->query("ALTER TABLE assignments ADD COLUMN IF NOT EXISTS exam_date DATE");
$conn->query("ALTER TABLE assignments ADD COLUMN IF NOT EXISTS total_points INT DEFAULT 0");
$conn->query("ALTER TABLE assignments ADD COLUMN IF NOT EXISTS questions LONGTEXT");

// ── Ensure lessons table has all required columns ────────────────
$conn->query("ALTER TABLE lessons ADD COLUMN IF NOT EXISTS content TEXT");
$conn->query("ALTER TABLE lessons ADD COLUMN IF NOT EXISTS duration_minutes INT DEFAULT 0");
$conn->query("ALTER TABLE lessons ADD COLUMN IF NOT EXISTS video_url VARCHAR(500)");
$conn->query("ALTER TABLE lessons ADD COLUMN IF NOT EXISTS order_num INT DEFAULT 1");

// ── Router ───────────────────────────────────────────────────────
switch ($action) {

    // ────────────────────────────────────────────────────────────
    case 'create_module':
        $title        = p('title');
        $subject_code = p('subject_code');
        $description  = p('description');

        if (!$title) respond('error', 'Module title is required.');

        $stmt = $conn->prepare("
            INSERT INTO modules (title, subject_code, description, teacher_id)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("sssi", $title, $subject_code, $description, $teacher_id);

        if ($stmt->execute()) {
            respond('success', 'Module created successfully!', [
                'id'    => $conn->insert_id,
                'title' => $title
            ]);
        } else {
            respond('error', 'Failed to create module: ' . $conn->error);
        }
        break;

    // ────────────────────────────────────────────────────────────
    case 'get_my_modules':
        $stmt = $conn->prepare("
            SELECT id, title, subject_code, description
            FROM modules
            WHERE teacher_id = ?
            ORDER BY id DESC
        ");
        $stmt->bind_param("i", $teacher_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        respond('success', 'Modules retrieved.', $rows);
        break;

    // ────────────────────────────────────────────────────────────
    case 'create_lesson':
        $module_id  = (int) p('module_id');
        $title      = p('title');
        $content    = p('content');
        $duration   = (int) p('duration_minutes', 0);
        $video_url  = p('video_url');
        $order_num  = (int) p('order_num', 1);

        if (!$module_id) respond('error', 'module_id is required.');
        if (!$title)     respond('error', 'Lesson title is required.');

        // Verify teacher owns this module
        $chk = $conn->prepare("SELECT id FROM modules WHERE id = ? AND teacher_id = ?");
        $chk->bind_param("ii", $module_id, $teacher_id);
        $chk->execute();
        if ($chk->get_result()->num_rows === 0) {
            respond('error', 'Module not found or access denied.');
        }

        $stmt = $conn->prepare("
            INSERT INTO lessons (module_id, title, content, duration_minutes, video_url, order_num)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("issisi", $module_id, $title, $content, $duration, $video_url, $order_num);

        if ($stmt->execute()) {
            respond('success', 'Lesson created successfully!', ['id' => $conn->insert_id]);
        } else {
            respond('error', 'Failed to create lesson: ' . $conn->error);
        }
        break;

    // ────────────────────────────────────────────────────────────
    case 'get_lessons':
        $module_id = (int) p('module_id');
        if (!$module_id) respond('error', 'module_id is required.');

        $stmt = $conn->prepare("
            SELECT id, title, content, duration_minutes, video_url, order_num
            FROM lessons
            WHERE module_id = ?
            ORDER BY order_num ASC
        ");
        $stmt->bind_param("i", $module_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        respond('success', 'Lessons retrieved.', $rows);
        break;

    // ────────────────────────────────────────────────────────────
    case 'create_quiz':
        $module_id     = (int) p('module_id');
        $title         = p('title');
        $type          = p('type', 'quiz');   // quiz | midterm | final | quarterly | other
        $time_limit    = (int) p('time_limit', 30);
        $passing_score = (int) p('passing_score', 75);
        $instructions  = p('instructions');
        $exam_date     = p('exam_date') ?: null;
        $questions_raw = pRaw('questions', '[]');

        if (!$module_id) respond('error', 'module_id is required.');
        if (!$title)     respond('error', 'Title is required.');

        // Verify ownership
        $chk = $conn->prepare("SELECT id FROM modules WHERE id = ? AND teacher_id = ?");
        $chk->bind_param("ii", $module_id, $teacher_id);
        $chk->execute();
        if ($chk->get_result()->num_rows === 0) {
            respond('error', 'Module not found or access denied.');
        }

        $questions = json_decode($questions_raw, true);
        if (!is_array($questions)) respond('error', 'Invalid questions format.');
        if (count($questions) === 0) respond('error', 'At least one question is required.');

        $total_points = array_sum(array_column($questions, 'points'));
        $q_json       = json_encode($questions);

        $stmt = $conn->prepare("
            INSERT INTO assignments
                (module_id, title, type, time_limit, passing_score, instructions, exam_date, total_points, questions)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "issiiisss",
            $module_id, $title, $type, $time_limit,
            $passing_score, $instructions, $exam_date,
            $total_points, $q_json
        );

        if ($stmt->execute()) {
            respond('success', ucfirst($type) . ' created successfully!', ['id' => $conn->insert_id]);
        } else {
            respond('error', 'Failed to save: ' . $conn->error);
        }
        break;

    // ────────────────────────────────────────────────────────────
    case 'enroll_student':
        $module_id     = (int) p('module_id');
        $student_email = p('student_email');

        if (!$module_id)     respond('error', 'module_id is required.');
        if (!$student_email) respond('error', 'student_email is required.');

        // Verify teacher owns the module
        $chk = $conn->prepare("SELECT id FROM modules WHERE id = ? AND teacher_id = ?");
        $chk->bind_param("ii", $module_id, $teacher_id);
        $chk->execute();
        if ($chk->get_result()->num_rows === 0) {
            respond('error', 'Module not found or access denied.');
        }

        // Find the student
        $schk = $conn->prepare("SELECT id, first_name, last_name FROM users WHERE email = ? AND role = 'student'");
        $schk->bind_param("s", $student_email);
        $schk->execute();
        $student = $schk->get_result()->fetch_assoc();
        if (!$student) respond('error', 'No student found with that email address.');

        // Already enrolled?
        $echk = $conn->prepare("SELECT id FROM enrollments WHERE student_id = ? AND module_id = ?");
        $echk->bind_param("ii", $student['id'], $module_id);
        $echk->execute();
        if ($echk->get_result()->num_rows > 0) {
            respond('error', 'This student is already enrolled in this module.');
        }

        // Enroll!
        $stmt = $conn->prepare("INSERT INTO enrollments (student_id, module_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $student['id'], $module_id);

        if ($stmt->execute()) {
            respond('success', $student['first_name'] . ' ' . $student['last_name'] . ' enrolled successfully! ✅');
        } else {
            respond('error', 'Failed to enroll student: ' . $conn->error);
        }
        break;

    // ────────────────────────────────────────────────────────────
    case 'get_enrolled_students':
        $module_id = (int) p('module_id');
        if (!$module_id) respond('error', 'module_id is required.');

        // Verify ownership
        $chk = $conn->prepare("SELECT id FROM modules WHERE id = ? AND teacher_id = ?");
        $chk->bind_param("ii", $module_id, $teacher_id);
        $chk->execute();
        if ($chk->get_result()->num_rows === 0) {
            respond('error', 'Module not found or access denied.');
        }

        $stmt = $conn->prepare("
            SELECT u.id, u.first_name, u.last_name, u.email, e.enrolled_at
            FROM enrollments e
            JOIN users u ON e.student_id = u.id
            WHERE e.module_id = ?
            ORDER BY e.enrolled_at DESC
        ");
        $stmt->bind_param("i", $module_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        respond('success', 'Students retrieved.', $rows);
        break;

    // ────────────────────────────────────────────────────────────
    default:
        respond('error', 'Invalid action. Valid actions: create_module, get_my_modules, create_lesson, get_lessons, create_quiz, enroll_student, get_enrolled_students');
}
?>
