<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../assets/config/db_config.php';
require_once __DIR__ . '/../assets/auth/auth.php';
$auth = new Auth();
$auth->requireRole(['super_admin', 'admin']);
$pdo = getDBConnection();
try {
    $limit = min((int)($_GET['limit'] ?? 100), 500);
    $offset = max((int)($_GET['offset'] ?? 0), 0);
    $stmt = $pdo->prepare(
        "SELECT ua.id, ua.action, ua.action_type, ua.details, ua.ip_address, ua.created_at,
                u.username
         FROM user_activity ua
         LEFT JOIN users u ON ua.user_id = u.id
         ORDER BY ua.created_at DESC
         LIMIT ? OFFSET ?"
    );
    $stmt->execute([$limit, $offset]);
    $activities = $stmt->fetchAll();
    echo json_encode(['success' => true, 'activities' => $activities]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
