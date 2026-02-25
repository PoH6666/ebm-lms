<?php
ini_set('display_errors', 0);
error_reporting(0);
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    echo json_encode(['status' => 'ok']);
    exit;
}

function respond($data) {
    ob_end_clean();
    echo json_encode($data);
    exit;
}

// Direct DB connection using Railway env vars
function getDB() {
    $host = getenv('MYSQLHOST') ?: getenv('DB_HOST') ?: 'localhost';
    $user = getenv('MYSQLUSER') ?: getenv('DB_USER') ?: 'root';
    $pass = getenv('MYSQLPASSWORD') ?: getenv('DB_PASSWORD') ?: '';
    $db   = getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: 'railway';
    $port = getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: 3306;

    $conn = new mysqli($host, $user, $pass, $db, (int)$port);
    if ($conn->connect_error) {
        respond(['status' => 'error', 'message' => 'DB connection failed: ' . $conn->connect_error]);
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

// Get teacher_id from POST, GET, or session
$teacher_id = null;
if (!empty($_POST['teacher_id'])) {
    $teacher_id = (int)$_POST['teacher_id'];
} elseif (!empty($_GET['teacher_id'])) {
    $teacher_id = (int)$_GET['teacher_id'];
} elseif (!empty($_SESSION['user_id'])) {
    $teacher_id = (int)$_SESSION['user_id'];
}

$action = $_POST['action'] ?? $_GET['action'] ?? 'ping';

if ($action === 'ping') {
    respond(['status' => 'ok', 'message' => 'teacher.php is alive', 'teacher_id' => $teacher_id]);
}

if (!$teacher_id) {
    respond(['status' => 'error', 'message' => 'Not authenticated. teacher_id missing.']);
}

$conn = getDB();

// Ensure subjects table has needed columns
$cols = ['description TEXT', 'subject_code VARCHAR(50)', 'teacher_id INT'];
foreach ($cols as $col) {
    $colName = explode(' ', $col)[0];
    $check = $conn->query("SHOW COLUMNS FROM subjects LIKE '$colName'");
    if ($check && $check->num_rows === 0) {
        $conn->query("ALTER TABLE subjects ADD COLUMN $col");
    }
}

// Ensure enrollments table exists
$conn->query("CREATE TABLE IF NOT EXISTS enrollments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    subject_id INT NOT NULL,
    enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_enrollment (student_id, subject_id)
)");

// Ensure lessons, quizzes, exams tables have subject_id
foreach (['lessons', 'quizzes', 'exams'] as $tbl) {
    $check = $conn->query("SHOW COLUMNS FROM $tbl LIKE 'subject_id'");
    if ($check && $check->num_rows === 0) {
        $conn->query("ALTER TABLE $tbl ADD COLUMN subject_id INT");
    }
}

switch ($action) {

    case 'get_my_subjects':
        $stmt = $conn->prepare("SELECT * FROM subjects WHERE teacher_id = ? ORDER BY created_at DESC");
        $stmt->bind_param('i', $teacher_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $subjects = [];
        while ($row = $result->fetch_assoc()) $subjects[] = $row;
        respond(['status' => 'success', 'subjects' => $subjects]);

    case 'create_subject':
        $name = trim($_POST['subject_name'] ?? '');
        $code = trim($_POST['subject_code'] ?? '');
        $desc = trim($_POST['description'] ?? '');

        if (!$name || !$code) {
            respond(['status' => 'error', 'message' => 'Subject name and code are required']);
        }

        // Check duplicate code
        $check = $conn->prepare("SELECT id FROM subjects WHERE subject_code = ?");
        $check->bind_param('s', $code);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            respond(['status' => 'error', 'message' => 'Subject code already exists']);
        }

        $stmt = $conn->prepare("INSERT INTO subjects (subject_name, subject_code, description, teacher_id, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param('sssi', $name, $code, $desc, $teacher_id);
        if ($stmt->execute()) {
            respond(['status' => 'success', 'message' => 'Subject created successfully', 'subject_id' => $conn->insert_id]);
        } else {
            respond(['status' => 'error', 'message' => 'Failed to create subject: ' . $conn->error]);
        }

    case 'get_students':
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        if (!$subject_id) {
            // All students enrolled in teacher's subjects
            $stmt = $conn->prepare("
                SELECT DISTINCT u.id, u.username, u.email, s.subject_name, e.enrolled_at
                FROM enrollments e
                JOIN users u ON e.student_id = u.id
                JOIN subjects s ON e.subject_id = s.id
                WHERE s.teacher_id = ?
                ORDER BY e.enrolled_at DESC
            ");
            $stmt->bind_param('i', $teacher_id);
        } else {
            $stmt = $conn->prepare("
                SELECT u.id, u.username, u.email, e.enrolled_at
                FROM enrollments e
                JOIN users u ON e.student_id = u.id
                WHERE e.subject_id = ?
                ORDER BY e.enrolled_at DESC
            ");
            $stmt->bind_param('i', $subject_id);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $students = [];
        while ($row = $result->fetch_assoc()) $students[] = $row;
        respond(['status' => 'success', 'students' => $students]);

    case 'enroll_student':
        $student_id = (int)($_POST['student_id'] ?? 0);
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        if (!$student_id || !$subject_id) {
            respond(['status' => 'error', 'message' => 'student_id and subject_id required']);
        }
        $stmt = $conn->prepare("INSERT IGNORE INTO enrollments (student_id, subject_id) VALUES (?, ?)");
        $stmt->bind_param('ii', $student_id, $subject_id);
        $stmt->execute();
        respond(['status' => 'success', 'message' => 'Student enrolled']);

    case 'create_lesson':
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        if (!$subject_id || !$title) {
            respond(['status' => 'error', 'message' => 'subject_id and title required']);
        }
        $stmt = $conn->prepare("INSERT INTO lessons (subject_id, title, content, teacher_id, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param('issi', $subject_id, $title, $content, $teacher_id);
        if ($stmt->execute()) {
            respond(['status' => 'success', 'message' => 'Lesson created', 'lesson_id' => $conn->insert_id]);
        } else {
            respond(['status' => 'error', 'message' => $conn->error]);
        }

    case 'get_lessons':
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        if ($subject_id) {
            $stmt = $conn->prepare("SELECT * FROM lessons WHERE subject_id = ? ORDER BY created_at DESC");
            $stmt->bind_param('i', $subject_id);
        } else {
            $stmt = $conn->prepare("SELECT l.*, s.subject_name FROM lessons l JOIN subjects s ON l.subject_id = s.id WHERE l.teacher_id = ? ORDER BY l.created_at DESC");
            $stmt->bind_param('i', $teacher_id);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $lessons = [];
        while ($row = $result->fetch_assoc()) $lessons[] = $row;
        respond(['status' => 'success', 'lessons' => $lessons]);

    case 'create_quiz':
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $questions = $_POST['questions'] ?? '[]';
        if (!$subject_id || !$title) {
            respond(['status' => 'error', 'message' => 'subject_id and title required']);
        }
        $stmt = $conn->prepare("INSERT INTO quizzes (subject_id, title, questions, teacher_id, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param('issi', $subject_id, $title, $questions, $teacher_id);
        if ($stmt->execute()) {
            respond(['status' => 'success', 'message' => 'Quiz created', 'quiz_id' => $conn->insert_id]);
        } else {
            respond(['status' => 'error', 'message' => $conn->error]);
        }

    case 'get_quizzes':
        $stmt = $conn->prepare("SELECT q.*, s.subject_name FROM quizzes q JOIN subjects s ON q.subject_id = s.id WHERE q.teacher_id = ? ORDER BY q.created_at DESC");
        $stmt->bind_param('i', $teacher_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $quizzes = [];
        while ($row = $result->fetch_assoc()) $quizzes[] = $row;
        respond(['status' => 'success', 'quizzes' => $quizzes]);

    case 'create_exam':
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $questions = $_POST['questions'] ?? '[]';
        $duration = (int)($_POST['duration'] ?? 60);
        if (!$subject_id || !$title) {
            respond(['status' => 'error', 'message' => 'subject_id and title required']);
        }
        $stmt = $conn->prepare("INSERT INTO exams (subject_id, title, questions, duration, teacher_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param('issii', $subject_id, $title, $questions, $duration, $teacher_id);
        if ($stmt->execute()) {
            respond(['status' => 'success', 'message' => 'Exam created', 'exam_id' => $conn->insert_id]);
        } else {
            respond(['status' => 'error', 'message' => $conn->error]);
        }

    case 'get_exams':
        $stmt = $conn->prepare("SELECT e.*, s.subject_name FROM exams e JOIN subjects s ON e.subject_id = s.id WHERE e.teacher_id = ? ORDER BY e.created_at DESC");
        $stmt->bind_param('i', $teacher_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $exams = [];
        while ($row = $result->fetch_assoc()) $exams[] = $row;
        respond(['status' => 'success', 'exams' => $exams]);

    case 'get_all_students':
        $result = $conn->query("SELECT id, username, email FROM users WHERE role = 'student' ORDER BY username");
        $students = [];
        while ($row = $result->fetch_assoc()) $students[] = $row;
        respond(['status' => 'success', 'students' => $students]);

    default:
        respond(['status' => 'error', 'message' => 'Unknown action: ' . $action]);
}

$conn->close();
