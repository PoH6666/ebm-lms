<?php
error_reporting(0);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

ob_clean();
header('Content-Type: application/json');

function respond($status, $message, $data = null) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]);
    exit();
}

$conn = getConnection();

// Auto-migrate silently
@$conn->query("ALTER TABLE subjects ADD COLUMN subject_code VARCHAR(50) DEFAULT ''");
@$conn->query("ALTER TABLE subjects ADD COLUMN description TEXT");
@$conn->query("ALTER TABLE subjects ADD COLUMN teacher_id INT DEFAULT NULL");
@$conn->query("ALTER TABLE lessons ADD COLUMN subject_id INT DEFAULT NULL");
@$conn->query("ALTER TABLE lessons ADD COLUMN video_url VARCHAR(500) DEFAULT ''");
@$conn->query("ALTER TABLE lessons ADD COLUMN duration INT DEFAULT 0");
@$conn->query("ALTER TABLE lessons ADD COLUMN lesson_order INT DEFAULT 0");
@$conn->query("ALTER TABLE assignments ADD COLUMN subject_id INT DEFAULT NULL");
@$conn->query("ALTER TABLE assignments ADD COLUMN type VARCHAR(20) DEFAULT 'quiz'");
@$conn->query("ALTER TABLE assignments ADD COLUMN time_limit INT DEFAULT 30");
@$conn->query("ALTER TABLE assignments ADD COLUMN passing_score INT DEFAULT 75");
@$conn->query("ALTER TABLE assignments ADD COLUMN instructions TEXT");
@$conn->query("ALTER TABLE assignments ADD COLUMN exam_date DATE");
@$conn->query("ALTER TABLE assignments ADD COLUMN total_points INT DEFAULT 0");
@$conn->query("ALTER TABLE assignments ADD COLUMN questions LONGTEXT");

$action = $_POST['action'] ?? $_GET['action'] ?? '';
if (!$action) respond('error', 'No action specified.');

if ($action === 'create_subject') {
    $teacher_id   = intval($_POST['teacher_id'] ?? 0);
    $subject_name = trim($_POST['subject_name'] ?? '');
    $subject_code = strtoupper(trim($_POST['subject_code'] ?? ''));
    $description  = trim($_POST['description'] ?? '');
    if (!$teacher_id)   respond('error', 'Teacher ID is required.');
    if (!$subject_name) respond('error', 'Subject name is required.');
    if (!$subject_code) respond('error', 'Subject code is required.');
    $chk = $conn->prepare("SELECT id FROM subjects WHERE subject_code = ? AND teacher_id = ?");
    $chk->bind_param("si", $subject_code, $teacher_id);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) respond('error', 'A subject with this code already exists.');
    $stmt = $conn->prepare("INSERT INTO subjects (subject_name, subject_code, description, teacher_id, created_at) VALUES (?, ?, ?, ?, NOW())");
    if (!$stmt) respond('error', 'Prepare failed: ' . $conn->error);
    $stmt->bind_param("sssi", $subject_name, $subject_code, $description, $teacher_id);
    if ($stmt->execute()) {
        respond('success', 'Subject created successfully!', ['id' => $conn->insert_id]);
    } else {
        respond('error', 'Insert failed: ' . $stmt->error);
    }
}

if ($action === 'get_my_subjects') {
    $teacher_id = intval($_POST['teacher_id'] ?? $_GET['teacher_id'] ?? 0);
    if (!$teacher_id) respond('error', 'Teacher ID required.');
    $stmt = $conn->prepare("
        SELECT s.id, s.subject_name, s.subject_code, s.description,
               COUNT(DISTINCT e.id) AS student_count,
               COUNT(DISTINCT l.id) AS lesson_count
        FROM subjects s
        LEFT JOIN enrollments e ON e.subject_id = s.id
        LEFT JOIN lessons l ON l.subject_id = s.id
        WHERE s.teacher_id = ?
        GROUP BY s.id
        ORDER BY s.created_at DESC
    ");
    if (!$stmt) respond('error', 'Prepare failed: ' . $conn->error);
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $list = [];
    while ($row = $res->fetch_assoc()) $list[] = $row;
    respond('success', 'OK', $list);
}

if ($action === 'create_lesson') {
    $teacher_id   = intval($_POST['teacher_id'] ?? 0);
    $subject_id   = intval($_POST['subject_id'] ?? 0);
    $title        = trim($_POST['title'] ?? '');
    $content      = trim($_POST['content'] ?? '');
    $video_url    = trim($_POST['video_url'] ?? '');
    $duration     = intval($_POST['duration'] ?? 0);
    $lesson_order = intval($_POST['lesson_order'] ?? 1);
    if (!$teacher_id || !$subject_id || !$title) respond('error', 'Teacher, subject, and title required.');
    $own = $conn->prepare("SELECT id FROM subjects WHERE id = ? AND teacher_id = ?");
    $own->bind_param("ii", $subject_id, $teacher_id);
    $own->execute();
    if ($own->get_result()->num_rows === 0) respond('error', 'You do not own this subject.');
    $stmt = $conn->prepare("INSERT INTO lessons (subject_id, title, content, video_url, duration, lesson_order, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("isssii", $subject_id, $title, $content, $video_url, $duration, $lesson_order);
    if ($stmt->execute()) {
        respond('success', 'Lesson created!', ['id' => $conn->insert_id]);
    } else {
        respond('error', 'Failed: ' . $stmt->error);
    }
}

if ($action === 'get_lessons') {
    $subject_id = intval($_POST['subject_id'] ?? $_GET['subject_id'] ?? 0);
    if (!$subject_id) respond('error', 'Subject ID required.');
    $stmt = $conn->prepare("SELECT * FROM lessons WHERE subject_id = ? ORDER BY lesson_order ASC, created_at ASC");
    $stmt->bind_param("i", $subject_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $list = [];
    while ($row = $res->fetch_assoc()) $list[] = $row;
    respond('success', 'OK', $list);
}

if ($action === 'create_quiz') {
    $teacher_id    = intval($_POST['teacher_id'] ?? 0);
    $subject_id    = intval($_POST['subject_id'] ?? 0);
    $title         = trim($_POST['title'] ?? '');
    $instructions  = trim($_POST['instructions'] ?? '');
    $time_limit    = intval($_POST['time_limit'] ?? 30);
    $passing_score = intval($_POST['passing_score'] ?? 75);
    $questions     = $_POST['questions'] ?? '[]';
    if (!$teacher_id || !$subject_id || !$title) respond('error', 'Required fields missing.');
    $own = $conn->prepare("SELECT id FROM subjects WHERE id = ? AND teacher_id = ?");
    $own->bind_param("ii", $subject_id, $teacher_id);
    $own->execute();
    if ($own->get_result()->num_rows === 0) respond('error', 'You do not own this subject.');
    $type = 'quiz';
    $stmt = $conn->prepare("INSERT INTO assignments (subject_id, title, instructions, type, time_limit, passing_score, questions, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("isssiis", $subject_id, $title, $instructions, $type, $time_limit, $passing_score, $questions);
    if ($stmt->execute()) {
        respond('success', 'Quiz created!', ['id' => $conn->insert_id]);
    } else {
        respond('error', 'Failed: ' . $stmt->error);
    }
}

if ($action === 'create_exam') {
    $teacher_id    = intval($_POST['teacher_id'] ?? 0);
    $subject_id    = intval($_POST['subject_id'] ?? 0);
    $title         = trim($_POST['title'] ?? '');
    $instructions  = trim($_POST['instructions'] ?? '');
    $time_limit    = intval($_POST['time_limit'] ?? 60);
    $passing_score = intval($_POST['passing_score'] ?? 75);
    $exam_date     = trim($_POST['exam_date'] ?? '');
    $type          = trim($_POST['exam_type'] ?? 'midterm');
    $questions     = $_POST['questions'] ?? '[]';
    if (!$teacher_id || !$subject_id || !$title) respond('error', 'Required fields missing.');
    $own = $conn->prepare("SELECT id FROM subjects WHERE id = ? AND teacher_id = ?");
    $own->bind_param("ii", $subject_id, $teacher_id);
    $own->execute();
    if ($own->get_result()->num_rows === 0) respond('error', 'You do not own this subject.');
    $stmt = $conn->prepare("INSERT INTO assignments (subject_id, title, instructions, type, time_limit, passing_score, exam_date, questions, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("isssiiis", $subject_id, $title, $instructions, $type, $time_limit, $passing_score, $exam_date, $questions);
    if ($stmt->execute()) {
        respond('success', 'Exam created!', ['id' => $conn->insert_id]);
    } else {
        respond('error', 'Failed: ' . $stmt->error);
    }
}

if ($action === 'enroll_student') {
    $teacher_id = intval($_POST['teacher_id'] ?? 0);
    $subject_id = intval($_POST['subject_id'] ?? 0);
    $email      = trim($_POST['student_email'] ?? '');
    if (!$teacher_id || !$subject_id || !$email) respond('error', 'All fields required.');
    $own = $conn->prepare("SELECT id FROM subjects WHERE id = ? AND teacher_id = ?");
    $own->bind_param("ii", $subject_id, $teacher_id);
    $own->execute();
    if ($own->get_result()->num_rows === 0) respond('error', 'You do not own this subject.');
    $usr = $conn->prepare("SELECT id, first_name, last_name FROM users WHERE email = ? AND role = 'student'");
    $usr->bind_param("s", $email);
    $usr->execute();
    $student = $usr->get_result()->fetch_assoc();
    if (!$student) respond('error', 'No student found with that email.');
    $dup = $conn->prepare("SELECT id FROM enrollments WHERE student_id = ? AND subject_id = ?");
    $dup->bind_param("ii", $student['id'], $subject_id);
    $dup->execute();
    if ($dup->get_result()->num_rows > 0) respond('error', 'Student is already enrolled.');
    $enr = $conn->prepare("INSERT INTO enrollments (student_id, subject_id, enrolled_at) VALUES (?, ?, NOW())");
    $enr->bind_param("ii", $student['id'], $subject_id);
    if ($enr->execute()) {
        respond('success', "Enrolled {$student['first_name']} {$student['last_name']} successfully!");
    } else {
        respond('error', 'Failed: ' . $enr->error);
    }
}

if ($action === 'get_enrolled_students') {
    $subject_id = intval($_POST['subject_id'] ?? $_GET['subject_id'] ?? 0);
    if (!$subject_id) respond('error', 'Subject ID required.');
    $stmt = $conn->prepare("
        SELECT u.id, u.first_name, u.last_name, u.email, u.school_id, e.enrolled_at
        FROM enrollments e
        JOIN users u ON u.id = e.student_id
        WHERE e.subject_id = ?
        ORDER BY u.last_name ASC
    ");
    $stmt->bind_param("i", $subject_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $list = [];
    while ($row = $res->fetch_assoc()) $list[] = $row;
    respond('success', 'OK', $list);
}

respond('error', 'Unknown action: ' . $action);
