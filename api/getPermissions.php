<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../assets/config/db_config.php';
require_once __DIR__ . '/../assets/auth/auth.php';
$auth = new Auth();
$auth->requireRole(['super_admin', 'admin']);
$pdo = getDBConnection();
$user_id = (int)($_GET['user_id'] ?? 0);
if (!$user_id) {
    echo json_encode(['success' => false, 'error' => 'user_id required']);
    exit;
}
try {
    $stmt = $pdo->prepare(
        "SELECT dp.*, d.domain_name FROM domain_permissions dp
         JOIN domains d ON dp.domain_id = d.id
         WHERE dp.user_id = ?"
    );
    $stmt->execute([$user_id]);
    $permissions = $stmt->fetchAll();
    echo json_encode(['success' => true, 'permissions' => $permissions]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
