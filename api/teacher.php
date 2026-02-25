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

require_once __DIR__ . '/../config/database.php';

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

$action     = p('action');
$teacher_id = (int) p('teacher_id');

if (!$teacher_id) respond('error', 'teacher_id is required.');

$conn = getConnection();

// Auto-migrate lessons & assignments columns silently
$conn->query("ALTER TABLE lessons ADD COLUMN IF NOT EXISTS content TEXT");
$conn->query("ALTER TABLE lessons ADD COLUMN IF NOT EXISTS duration_minutes INT DEFAULT 0");
$conn->query("ALTER TABLE lessons ADD COLUMN IF NOT EXISTS video_url VARCHAR(500)");
$conn->query("ALTER TABLE lessons ADD COLUMN IF NOT EXISTS order_num INT DEFAULT 1");
$conn->query("ALTER TABLE assignments ADD COLUMN IF NOT EXISTS type VARCHAR(20) DEFAULT 'quiz'");
$conn->query("ALTER TABLE assignments ADD COLUMN IF NOT EXISTS time_limit INT DEFAULT 30");
$conn->query("ALTER TABLE assignments ADD COLUMN IF NOT EXISTS passing_score INT DEFAULT 75");
$conn->query("ALTER TABLE assignments ADD COLUMN IF NOT EXISTS instructions TEXT");
$conn->query("ALTER TABLE assignments ADD COLUMN IF NOT EXISTS exam_date DATE");
$conn->query("ALTER TABLE assignments ADD COLUMN IF NOT EXISTS total_points INT DEFAULT 0");
$conn->query("ALTER TABLE assignments ADD COLUMN IF NOT EXISTS questions LONGTEXT");

switch ($action) {

    // ── Create Subject ───────────────────────────────────────────
    case 'create_subject':
        $subject_name = p('subject_name');
        $subject_code = p('subject_code');
        $description  = p('description');

        if (!$subject_name) respond('error', 'Subject name is required.');

        $stmt = $conn->prepare("INSERT INTO subjects (subject_name, subject_code, description, teacher_id) VALUES (?,?,?,?)");
        $stmt->bind_param("sssi", $subject_name, $subject_code, $description, $teacher_id);

        if ($stmt->execute()) {
            respond('success', 'Subject created successfully!', [
                'id'           => $conn->insert_id,
                'subject_name' => $subject_name
            ]);
        } else {
            respond('error', 'Failed to create subject: ' . $conn->error);
        }
        break;

    // ── Get Teacher's Subjects ───────────────────────────────────
    case 'get_my_subjects':
        $stmt = $conn->prepare("
            SELECT id, subject_name, subject_code, description
            FROM subjects
            WHERE teacher_id = ?
            ORDER BY id DESC
        ");
        $stmt->bind_param("i", $teacher_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        respond('success', 'Subjects retrieved.', $rows);
        break;

    // ── Create Lesson ────────────────────────────────────────────
    case 'create_lesson':
        $subject_id = (int) p('subject_id');
        $title      = p('title');
        $content    = p('content');
        $duration   = (int) p('duration_minutes', 0);
        $video_url  = p('video_url');
        $order_num  = (int) p('order_num', 1);

        if (!$subject_id) respond('error', 'subject_id is required.');
        if (!$title)      respond('error', 'Lesson title is required.');

        $chk = $conn->prepare("SELECT id FROM subjects WHERE id = ? AND teacher_id = ?");
        $chk->bind_param("ii", $subject_id, $teacher_id);
        $chk->execute();
        if ($chk->get_result()->num_rows === 0) respond('error', 'Subject not found or access denied.');

        $stmt = $conn->prepare("INSERT INTO lessons (subject_id, title, content, duration_minutes, video_url, order_num) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param("issisi", $subject_id, $title, $content, $duration, $video_url, $order_num);

        if ($stmt->execute()) {
            respond('success', 'Lesson created successfully!', ['id' => $conn->insert_id]);
        } else {
            respond('error', 'Failed to create lesson: ' . $conn->error);
        }
        break;

    // ── Get Lessons ──────────────────────────────────────────────
    case 'get_lessons':
        $subject_id = (int) p('subject_id');
        if (!$subject_id) respond('error', 'subject_id is required.');

        $stmt = $conn->prepare("
            SELECT id, title, content, duration_minutes, video_url, order_num
            FROM lessons WHERE subject_id = ? ORDER BY order_num ASC
        ");
        $stmt->bind_param("i", $subject_id);
        $stmt->execute();
        respond('success', 'Lessons retrieved.', $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        break;

    // ── Create Quiz / Exam ───────────────────────────────────────
    case 'create_quiz':
        $subject_id    = (int) p('subject_id');
        $title         = p('title');
        $type          = p('type', 'quiz');
        $time_limit    = (int) p('time_limit', 30);
        $passing_score = (int) p('passing_score', 75);
        $instructions  = p('instructions');
        $exam_date     = p('exam_date') ?: null;
        $questions_raw = pRaw('questions', '[]');

        if (!$subject_id) respond('error', 'subject_id is required.');
        if (!$title)      respond('error', 'Title is required.');

        $chk = $conn->prepare("SELECT id FROM subjects WHERE id = ? AND teacher_id = ?");
        $chk->bind_param("ii", $subject_id, $teacher_id);
        $chk->execute();
        if ($chk->get_result()->num_rows === 0) respond('error', 'Subject not found or access denied.');

        $questions = json_decode($questions_raw, true);
        if (!is_array($questions) || count($questions) === 0) respond('error', 'At least one question is required.');

        $total_points = array_sum(array_column($questions, 'points'));
        $q_json       = json_encode($questions);

        $stmt = $conn->prepare("
            INSERT INTO assignments (subject_id, title, type, time_limit, passing_score, instructions, exam_date, total_points, questions)
            VALUES (?,?,?,?,?,?,?,?,?)
        ");
        $stmt->bind_param("issiiisss", $subject_id, $title, $type, $time_limit, $passing_score, $instructions, $exam_date, $total_points, $q_json);

        if ($stmt->execute()) {
            respond('success', ucfirst($type) . ' created successfully!', ['id' => $conn->insert_id]);
        } else {
            respond('error', 'Failed to save: ' . $conn->error);
        }
        break;

    // ── Enroll Student ───────────────────────────────────────────
    case 'enroll_student':
        $subject_id    = (int) p('subject_id');
        $student_email = p('student_email');

        if (!$subject_id)    respond('error', 'subject_id is required.');
        if (!$student_email) respond('error', 'student_email is required.');

        $chk = $conn->prepare("SELECT id FROM subjects WHERE id = ? AND teacher_id = ?");
        $chk->bind_param("ii", $subject_id, $teacher_id);
        $chk->execute();
        if ($chk->get_result()->num_rows === 0) respond('error', 'Subject not found or access denied.');

        $schk = $conn->prepare("SELECT id, first_name, last_name FROM users WHERE email = ? AND role = 'student'");
        $schk->bind_param("s", $student_email);
        $schk->execute();
        $student = $schk->get_result()->fetch_assoc();
        if (!$student) respond('error', 'No student found with that email.');

        $echk = $conn->prepare("SELECT id FROM enrollments WHERE student_id = ? AND subject_id = ?");
        $echk->bind_param("ii", $student['id'], $subject_id);
        $echk->execute();
        if ($echk->get_result()->num_rows > 0) respond('error', 'Student is already enrolled in this subject.');

        $stmt = $conn->prepare("INSERT INTO enrollments (student_id, subject_id) VALUES (?,?)");
        $stmt->bind_param("ii", $student['id'], $subject_id);

        if ($stmt->execute()) {
            respond('success', $student['first_name'] . ' ' . $student['last_name'] . ' enrolled successfully! ✅');
        } else {
            respond('error', 'Failed to enroll: ' . $conn->error);
        }
        break;

    // ── Get Enrolled Students ────────────────────────────────────
    case 'get_enrolled_students':
        $subject_id = (int) p('subject_id');
        if (!$subject_id) respond('error', 'subject_id is required.');

        $chk = $conn->prepare("SELECT id FROM subjects WHERE id = ? AND teacher_id = ?");
        $chk->bind_param("ii", $subject_id, $teacher_id);
        $chk->execute();
        if ($chk->get_result()->num_rows === 0) respond('error', 'Subject not found or access denied.');

        $stmt = $conn->prepare("
            SELECT u.id, u.first_name, u.last_name, u.email, e.enrolled_at
            FROM enrollments e
            JOIN users u ON e.student_id = u.id
            WHERE e.subject_id = ?
            ORDER BY e.enrolled_at DESC
        ");
        $stmt->bind_param("i", $subject_id);
        $stmt->execute();
        respond('success', 'Students retrieved.', $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        break;

    default:
        respond('error', 'Invalid action.');
}
