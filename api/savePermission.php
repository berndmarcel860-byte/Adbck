<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../assets/config/db_config.php';
require_once __DIR__ . '/../assets/auth/auth.php';
$auth = new Auth();
$auth->requireRole(['super_admin', 'admin']);
$pdo = getDBConnection();
$user_id         = (int)($_POST['user_id'] ?? 0);
$domain_id       = (int)($_POST['domain_id'] ?? 0);
$can_view        = isset($_POST['can_view']) ? 1 : 0;
$can_edit        = isset($_POST['can_edit']) ? 1 : 0;
$can_delete      = isset($_POST['can_delete']) ? 1 : 0;
$can_send_commands = isset($_POST['can_send_commands']) ? 1 : 0;
if (!$user_id || !$domain_id) {
    echo json_encode(['success' => false, 'error' => 'user_id and domain_id required']);
    exit;
}
try {
    $stmt = $pdo->prepare(
        "INSERT INTO domain_permissions (user_id, domain_id, can_view, can_edit, can_delete, can_send_commands, assigned_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
             can_view = VALUES(can_view),
             can_edit = VALUES(can_edit),
             can_delete = VALUES(can_delete),
             can_send_commands = VALUES(can_send_commands)"
    );
    $stmt->execute([$user_id, $domain_id, $can_view, $can_edit, $can_delete, $can_send_commands, $_SESSION['user_id']]);
    echo json_encode(['success' => true, 'message' => 'Permission saved']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
