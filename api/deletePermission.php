<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../assets/config/db_config.php';
require_once __DIR__ . '/../assets/auth/auth.php';
$auth = new Auth();
$auth->requireRole(['super_admin', 'admin']);
$pdo = getDBConnection();
$user_id   = (int)($_POST['user_id'] ?? 0);
$domain_id = (int)($_POST['domain_id'] ?? 0);
if (!$user_id || !$domain_id) {
    echo json_encode(['success' => false, 'error' => 'user_id and domain_id required']);
    exit;
}
try {
    $stmt = $pdo->prepare("DELETE FROM domain_permissions WHERE user_id = ? AND domain_id = ?");
    $stmt->execute([$user_id, $domain_id]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
