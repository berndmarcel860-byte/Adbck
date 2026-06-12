<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../assets/config/db_config.php';
require_once __DIR__ . '/../assets/auth/auth.php';
$auth = new Auth();
$auth->requireRole(['super_admin', 'admin']);
$pdo = getDBConnection();
$rawUserId = $_POST['user_id'] ?? 'all';
$title     = trim($_POST['title'] ?? '');
$message   = trim($_POST['message'] ?? '');
$type      = $_POST['type'] ?? 'info';
$allowed   = ['info', 'success', 'warning', 'danger', 'primary'];
if (!in_array($type, $allowed)) $type = 'info';
if (empty($title) || empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Title and message are required']);
    exit;
}
try {
    if ($rawUserId === 'all') {
        $stmt = $pdo->prepare(
            "INSERT INTO notifications (user_id, title, message, type, is_global, created_by) VALUES (NULL, ?, ?, ?, 1, ?)"
        );
        $stmt->execute([$title, $message, $type, $_SESSION['user_id']]);
    } else {
        $userId = (int)$rawUserId;
        $stmt = $pdo->prepare(
            "INSERT INTO notifications (user_id, title, message, type, created_by) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$userId, $title, $message, $type, $_SESSION['user_id']]);
    }
    echo json_encode(['success' => true, 'message' => 'Notification sent']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
