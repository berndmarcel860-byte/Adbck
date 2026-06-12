<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../assets/config/db_config.php';
require_once __DIR__ . '/../assets/auth/auth.php';
$auth = new Auth();
$auth->requireRole(['super_admin', 'admin']);
$pdo = getDBConnection();
try {
    $userId = $_SESSION['user_id'];
    $stmt = $pdo->prepare(
        "SELECT * FROM notifications
         WHERE user_id = ? OR user_id IS NULL OR is_global = 1
         ORDER BY created_at DESC
         LIMIT 100"
    );
    $stmt->execute([$userId]);
    $notifications = $stmt->fetchAll();
    echo json_encode(['success' => true, 'notifications' => $notifications]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
