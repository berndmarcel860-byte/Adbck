<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../assets/config/db_config.php';
require_once __DIR__ . '/../assets/auth/auth.php';
$auth = new Auth();
$auth->requireRole(['super_admin', 'admin']);
$pdo = getDBConnection();
try {
    $stmt = $pdo->query("SELECT id, username, email, full_name, role, assigned_domain, created_at, last_login, is_active FROM users ORDER BY created_at DESC");
    echo json_encode(['success' => true, 'users' => $stmt->fetchAll()]);
} catch(Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
