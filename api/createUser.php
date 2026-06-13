<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../assets/config/db_config.php';
require_once __DIR__ . '/../assets/auth/auth.php';
$auth = new Auth();
$auth->requireRole(['super_admin', 'admin']);
$pdo = getDBConnection();
$username            = trim($_POST['username'] ?? '');
$password            = $_POST['password'] ?? '';
$email               = trim($_POST['email'] ?? '');
$full_name           = trim($_POST['full_name'] ?? '');
$role                = $_POST['role'] ?? 'viewer';
$assigned_domain     = trim($_POST['assigned_domain'] ?? '') ?: null;
$telegram_bot_token  = trim($_POST['telegram_bot_token'] ?? '') ?: null;
$telegram_chat_id    = trim($_POST['telegram_chat_id']   ?? '') ?: null;
$allowed_roles       = ['super_admin', 'admin', 'domain_admin', 'viewer'];
if (!in_array($role, $allowed_roles)) $role = 'viewer';
if (empty($username) || empty($password)) {
    echo json_encode(['success' => false, 'error' => 'Username and password required']);
    exit;
}
try {
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare(
        "INSERT INTO users (username, password, email, full_name, role, assigned_domain, telegram_bot_token, telegram_chat_id, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$username, $hashed, $email, $full_name, $role, $assigned_domain, $telegram_bot_token, $telegram_chat_id, $_SESSION['user_id']]);
    // Notify node server of Telegram config immediately
    if ($assigned_domain && $telegram_bot_token && $telegram_chat_id) {
        $ch = curl_init('http://localhost:8087/setDomainTelegram');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'domain'  => $assigned_domain,
            'token'   => $telegram_bot_token,
            'chatId'  => $telegram_chat_id,
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_exec($ch);
        curl_close($ch);
    }
    echo json_encode(['success' => true, 'message' => 'User created successfully']);
} catch(Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
