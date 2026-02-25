<?php
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/database.php';

function respond($status, $message, $data = null) {
    ob_end_clean();
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    http_response_code($status === 'success' ? 200 : 400);
    $res = ['status' => $status, 'message' => $message];
    if ($data !== null) $res['data'] = $data;
    echo json_encode($res);
    exit();
}

function clean($input) {
    if (is_array($input)) return array_map('clean', $input);
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function getParam($key, $default = '') {
    if (isset($_GET[$key]) && $_GET[$key] !== '') return clean($_GET[$key]);
    if (isset($_POST[$key]) && $_POST[$key] !== '') return clean($_POST[$key]);
    return $default;
}

function getRawParam($key, $default = '') {
    if (isset($_GET[$key]) && $_GET[$key] !== '') return $_GET[$key];
    if (isset($_POST[$key]) && $_POST[$key] !== '') return $_POST[$key];
    return $default;
}

$action = getParam('action');

switch ($action) {

    case 'login':
        $email    = getParam('email');
        $password = getRawParam('password');
        $role     = getParam('role', 'student');

        if (empty($email) || empty($password)) {
            respond('error', 'Email and password are required.');
        }

        $conn = getConnection();
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? AND role = ?");
        $stmt->bind_param("ss", $email, $role);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user || !password_verify($password, $user['password'])) {
            respond('error', 'Invalid email, password, or role.');
        }

        $_SESSION['user_id']    = $user['id'];
        $_SESSION['user_name']  = $user['first_name'] . ' ' . $user['last_name'];
        $_SESSION['user_role']  = $user['role'];
        $_SESSION['user_email'] = $user['email'];

        respond('success', 'Login successful!', [
            'id'         => $user['id'],
            'first_name' => $user['first_name'],
            'last_name'  => $user['last_name'],
            'name'       => $user['first_name'] . ' ' . $user['last_name'],
            'email'      => $user['email'],
            'role'       => $user['role'],
            'school_id'  => $user['school_id']
        ]);
        break;

    case 'register':
        $first_name = getParam('first_name');
        $last_name  = getParam('last_name');
        $email      = getParam('email');
        $password   = getRawParam('password');
        $school_id  = getParam('school_id');
        $role       = getParam('role', 'student');

        if (empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
            respond('error', 'All fields are required.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond('error', 'Invalid email format.');
        }
        if (strlen($password) < 6) {
            respond('error', 'Password must be at least 6 characters.');
        }
        if (!in_array($role, ['student', 'teacher'])) {
            respond('error', 'Invalid role for registration.');
        }

        $conn = getConnection();

        $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            respond('error', 'Email is already registered.');
        }

        $hashed = password_hash($password, PASSWORD_BCRYPT);
        $stmt   = $conn->prepare("INSERT INTO users (first_name, last_name, email, password, role, school_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $first_name, $last_name, $email, $hashed, $role, $school_id);

        if ($stmt->execute()) {
            $new_id = $conn->insert_id;
            $_SESSION['user_id']    = $new_id;
            $_SESSION['user_name']  = "$first_name $last_name";
            $_SESSION['user_role']  = $role;
            $_SESSION['user_email'] = $email;

            respond('success', 'Account created successfully!', [
                'id'         => $new_id,
                'first_name' => $first_name,
                'last_name'  => $last_name,
                'name'       => "$first_name $last_name",
                'email'      => $email,
                'role'       => $role,
                'school_id'  => $school_id
            ]);
        } else {
            respond('error', 'Failed to create account. Please try again.');
        }
        break;

    case 'get_enrolled_modules':
        $user_id = getParam('user_id');

        if (empty($user_id)) {
            respond('error', 'user_id is required.');
        }

        $conn = getConnection();

        // Get modules the student is enrolled in via the enrollments table
        $stmt = $conn->prepare("
            SELECT
                m.id,
                m.title,
                m.description,
                m.subject_code,
                m.teacher_id,
                CONCAT(t.first_name, ' ', t.last_name) AS teacher_name,
                e.enrolled_at,
                COALESCE(
                    ROUND(
                        (SELECT COUNT(*) FROM lesson_progress lp
                         JOIN lessons l ON lp.lesson_id = l.id
                         WHERE lp.user_id = ? AND l.module_id = m.id AND lp.completed = 1)
                        /
                        NULLIF((SELECT COUNT(*) FROM lessons l2 WHERE l2.module_id = m.id), 0)
                        * 100
                    ), 0
                ) AS progress
            FROM enrollments e
            JOIN modules m ON e.module_id = m.id
            LEFT JOIN users t ON m.teacher_id = t.id
            WHERE e.student_id = ?
            ORDER BY e.enrolled_at DESC
        ");
        $stmt->bind_param("ii", $user_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $modules = [];
        while ($row = $result->fetch_assoc()) {
            $modules[] = $row;
        }

        respond('success', 'Enrolled modules retrieved.', $modules);
        break;

    case 'logout':
        session_destroy();
        respond('success', 'Logged out successfully.');
        break;

    case 'check':
        if (isset($_SESSION['user_id'])) {
            respond('success', 'User is logged in.', [
                'id'   => $_SESSION['user_id'],
                'name' => $_SESSION['user_name'],
                'role' => $_SESSION['user_role']
            ]);
        } else {
            respond('error', 'Not logged in.');
        }
        break;

    default:
        respond('error', 'Invalid action.');
}
