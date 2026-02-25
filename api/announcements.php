<?php
// =========================================
// EBM_Server — Announcements API
// api/announcements.php
// =========================================

session_start();


require_once __DIR__ . '/../config/database.php';

requireLogin();

$action = isset($_GET['action']) ? clean($_GET['action']) : 'get';
$conn   = getConnection();

switch ($action) {

    // ---- GET ANNOUNCEMENTS ----
    case 'get':
        $role = $_SESSION['user_role'];
        $stmt = $conn->prepare("
            SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) AS posted_by
            FROM announcements a
            JOIN users u ON u.id = a.created_by
            WHERE a.target_role = 'all' OR a.target_role = ?
            ORDER BY a.created_at DESC
            LIMIT 20
        ");
        $stmt->bind_param("s", $role);
        $stmt->execute();
        $announcements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        respond('success', 'Announcements fetched.', $announcements);
        break;

    // ---- POST ANNOUNCEMENT (teacher/admin) ----
    case 'post':
        requireRole('teacher');

        $input       = json_decode(file_get_contents('php://input'), true);
        $title       = clean($input['title'] ?? '');
        $message     = clean($input['message'] ?? '');
        $target_role = clean($input['target_role'] ?? 'all');
        $created_by  = $_SESSION['user_id'];

        if (empty($title) || empty($message)) {
            respond('error', 'Title and message are required.');
        }

        $stmt = $conn->prepare("INSERT INTO announcements (title, message, created_by, target_role) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssis", $title, $message, $created_by, $target_role);

        if ($stmt->execute()) {
            respond('success', 'Announcement posted!', ['id' => $conn->insert_id]);
        } else {
            respond('error', 'Failed to post announcement.');
        }
        break;

    // ---- DELETE ANNOUNCEMENT ----
    case 'delete':
        requireRole('teacher');
        $ann_id = intval($_GET['id'] ?? 0);

        $stmt = $conn->prepare("DELETE FROM announcements WHERE id = ? AND created_by = ?");
        $stmt->bind_param("ii", $ann_id, $_SESSION['user_id']);

        if ($stmt->execute()) {
            respond('success', 'Announcement deleted.');
        } else {
            respond('error', 'Failed to delete announcement.');
        }
        break;

    default:
        respond('error', 'Invalid action.');
}
?>