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
        "SELECT COUNT(*) as cnt FROM notifications
         WHERE is_read = 0 AND (user_id = ? OR user_id IS NULL OR is_global = 1)"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    echo json_encode(['success' => true, 'count' => (int)$row['cnt']]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Failed to get notification count']);
}
?>
