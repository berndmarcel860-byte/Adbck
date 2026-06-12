<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../assets/config/db_config.php';
require_once __DIR__ . '/../assets/auth/auth.php';
$auth = new Auth();
$auth->requireRole(['super_admin', 'admin']);
$pdo = getDBConnection();
$user_id = $_POST['user_id'] ?? 0;
if ($user_id == $_SESSION['user_id']) {
    echo json_encode(['success' => false, 'error' => 'Cannot delete your own account']);
    exit;
}
try {
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
} catch(Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
