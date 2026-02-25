<?php
// =========================================
// EBM_Server — Users API
// api/users.php
// Handles: get users, update profile
// =========================================

session_start();

require_once __DIR__ . '/../includes/header.php';

require_once __DIR__ . '/../config/database.php';

requireLogin();

$action = isset($_GET['action']) ? clean($_GET['action']) : 'profile';
$conn   = getConnection();

switch ($action) {

    // ---- GET MY PROFILE ----
    case 'profile':
        $stmt = $conn->prepare("SELECT id, first_name, last_name, email, role, school_id, created_at FROM users WHERE id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        respond('success', 'Profile fetched.', $user);
        break;

    // ---- GET ALL USERS (admin only) ----
    case 'all':
        requireRole('admin');
        $result = $conn->query("SELECT id, first_name, last_name, email, role, school_id, created_at FROM users ORDER BY role, last_name");
        $users  = $result->fetch_all(MYSQLI_ASSOC);
        respond('success', 'Users fetched.', $users);
        break;

    // ---- GET STUDENTS ONLY ----
    case 'students':
        requireRole('teacher');
        $result = $conn->query("SELECT id, first_name, last_name, email, school_id FROM users WHERE role = 'student' ORDER BY last_name");
        $students = $result->fetch_all(MYSQLI_ASSOC);
        respond('success', 'Students fetched.', $students);
        break;

    // ---- UPDATE PROFILE ----
    case 'update':
        $input      = json_decode(file_get_contents('php://input'), true);
        $first_name = clean($input['first_name'] ?? '');
        $last_name  = clean($input['last_name'] ?? '');

        if (empty($first_name) || empty($last_name)) {
            respond('error', 'First and last name are required.');
        }

        $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ? WHERE id = ?");
        $stmt->bind_param("ssi", $first_name, $last_name, $_SESSION['user_id']);

        if ($stmt->execute()) {
            $_SESSION['user_name'] = "$first_name $last_name";
            respond('success', 'Profile updated!');
        } else {
            respond('error', 'Failed to update profile.');
        }
        break;

    // ---- CHANGE PASSWORD ----
    case 'change_password':
        $input        = json_decode(file_get_contents('php://input'), true);
        $old_password = $input['old_password'] ?? '';
        $new_password = $input['new_password'] ?? '';

        if (strlen($new_password) < 6) {
            respond('error', 'New password must be at least 6 characters.');
        }

        // Verify old password
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!password_verify($old_password, $user['password'])) {
            respond('error', 'Old password is incorrect.');
        }

        $hashed = password_hash($new_password, PASSWORD_BCRYPT);
        $update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $update->bind_param("si", $hashed, $_SESSION['user_id']);

        if ($update->execute()) {
            respond('success', 'Password changed successfully!');
        } else {
            respond('error', 'Failed to change password.');
        }
        break;

    // ---- DELETE USER (admin only) ----
    case 'delete':
        requireRole('admin');
        $user_id = intval($_GET['id'] ?? 0);

        if ($user_id === $_SESSION['user_id']) {
            respond('error', 'You cannot delete your own account.');
        }

        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);

        if ($stmt->execute()) {
            respond('success', 'User deleted.');
        } else {
            respond('error', 'Failed to delete user.');
        }
        break;

    default:
        respond('error', 'Invalid action.');
}
?>