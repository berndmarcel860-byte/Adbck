<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../assets/config/db_config.php';
require_once __DIR__ . '/../assets/auth/auth.php';
$auth = new Auth();
$auth->requireRole(['super_admin', 'admin']);
$pdo = getDBConnection();
$user_id             = (int)($_POST['user_id'] ?? 0);
$email               = trim($_POST['email'] ?? '');
$full_name           = trim($_POST['full_name'] ?? '');
$role                = $_POST['role'] ?? 'viewer';
$assigned_domain     = trim($_POST['assigned_domain'] ?? '') ?: null;
$is_active           = isset($_POST['is_active']) ? 1 : 0;
$telegram_bot_token  = trim($_POST['telegram_bot_token'] ?? '') ?: null;
$telegram_chat_id    = trim($_POST['telegram_chat_id']   ?? '') ?: null;
$allowed_roles       = ['super_admin', 'admin', 'domain_admin', 'viewer'];
if (!in_array($role, $allowed_roles)) $role = 'viewer';
try {
    $stmt = $pdo->prepare(
        "UPDATE users SET email = ?, full_name = ?, role = ?, assigned_domain = ?, is_active = ?, telegram_bot_token = ?, telegram_chat_id = ? WHERE id = ?"
    );
    $stmt->execute([$email, $full_name, $role, $assigned_domain, $is_active, $telegram_bot_token, $telegram_chat_id, $user_id]);
    // Notify node server of Telegram config immediately
    if ($assigned_domain && $telegram_bot_token && $telegram_chat_id) {
        $ch = curl_init('http://localhost:8087/setDomainTelegram');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'userId'  => $user_id,
            'domain'  => $assigned_domain,
            'token'   => $telegram_bot_token,
            'chatId'  => $telegram_chat_id,
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_exec($ch);
        curl_close($ch);
    }
    echo json_encode(['success' => true, 'message' => 'User updated successfully']);
} catch(Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
