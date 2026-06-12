<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../assets/config/db_config.php';
require_once __DIR__ . '/../assets/auth/auth.php';
$auth = new Auth();
$auth->requireRole(['super_admin', 'admin']);
$pdo = getDBConnection();
$domain_id = $_POST['domain_id'] ?? 0;
try {
    $stmt = $pdo->prepare("DELETE FROM domain_permissions WHERE domain_id = ?");
    $stmt->execute([$domain_id]);
    $stmt = $pdo->prepare("DELETE FROM domains WHERE id = ?");
    $stmt->execute([$domain_id]);
    echo json_encode(['success' => true, 'message' => 'Domain deleted successfully']);
} catch(Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
