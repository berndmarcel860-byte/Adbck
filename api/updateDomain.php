<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../assets/config/db_config.php';
require_once __DIR__ . '/../assets/auth/auth.php';
$auth = new Auth();
$auth->requireRole(['super_admin', 'admin']);
$pdo = getDBConnection();
$domain_id = $_POST['domain_id'] ?? 0;
$domain_name = $_POST['domain_name'] ?? '';
$description = $_POST['description'] ?? '';
$is_wildcard = isset($_POST['is_wildcard']) ? 1 : 0;
$status = $_POST['status'] ?? 'active';
try {
    $stmt = $pdo->prepare("UPDATE domains SET domain_name = ?, description = ?, is_wildcard = ?, status = ? WHERE id = ?");
    $stmt->execute([$domain_name, $description, $is_wildcard, $status, $domain_id]);
    echo json_encode(['success' => true, 'message' => 'Domain updated successfully']);
} catch(Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
