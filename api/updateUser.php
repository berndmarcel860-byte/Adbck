<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../assets/config/db_config.php';
require_once __DIR__ . '/../assets/auth/auth.php';
$auth = new Auth();
$auth->requireRole(['super_admin', 'admin']);
$pdo = getDBConnection();
$user_id = $_POST['user_id'] ?? 0;
$email = $_POST['email'] ?? '';
$full_name = $_POST['full_name'] ?? '';
$role = $_POST['role'] ?? 'viewer';
$is_active = isset($_POST['is_active']) ? 1 : 0;
try {
    $stmt = $pdo->prepare("UPDATE users SET email = ?, full_name = ?, role = ?, is_active = ? WHERE id = ?");
    $stmt->execute([$email, $full_name, $role, $is_active, $user_id]);
    echo json_encode(['success' => true, 'message' => 'User updated successfully']);
} catch(Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
