<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../assets/config/db_config.php';
require_once __DIR__ . '/../assets/auth/auth.php';
$auth = new Auth();
$auth->requireRole(['super_admin', 'admin']);
$pdo = getDBConnection();
$domain_name = $_POST['domain_name'] ?? '';
$description = $_POST['description'] ?? '';
$is_wildcard = isset($_POST['is_wildcard']) ? 1 : 0;
$status = $_POST['status'] ?? 'active';
if (empty($domain_name)) {
    echo json_encode(['success' => false, 'error' => 'Domain name required']);
    exit;
}
try {
    $stmt = $pdo->prepare("INSERT INTO domains (domain_name, description, is_wildcard, status, created_by) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$domain_name, $description, $is_wildcard, $status, $_SESSION['user_id']]);
    echo json_encode(['success' => true, 'message' => 'Domain created successfully']);
} catch(Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
