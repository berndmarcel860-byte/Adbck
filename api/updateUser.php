<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../assets/config/db_config.php';
require_once __DIR__ . '/../assets/auth/auth.php';
$auth = new Auth();
$auth->requireRole(['super_admin', 'admin']);
$pdo = getDBConnection();
$user_id         = (int)($_POST['user_id'] ?? 0);
$email           = trim($_POST['email'] ?? '');
$full_name       = trim($_POST['full_name'] ?? '');
$role            = $_POST['role'] ?? 'viewer';
$assigned_domain = trim($_POST['assigned_domain'] ?? '') ?: null;
$is_active       = isset($_POST['is_active']) ? 1 : 0;
$allowed_roles   = ['super_admin', 'admin', 'domain_admin', 'viewer'];
if (!in_array($role, $allowed_roles)) $role = 'viewer';
try {
    $stmt = $pdo->prepare(
        "UPDATE users SET email = ?, full_name = ?, role = ?, assigned_domain = ?, is_active = ? WHERE id = ?"
    );
    $stmt->execute([$email, $full_name, $role, $assigned_domain, $is_active, $user_id]);
    echo json_encode(['success' => true, 'message' => 'User updated successfully']);
} catch(Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
