<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../assets/config/db_config.php';
require_once __DIR__ . '/../assets/auth/auth.php';
$auth = new Auth();
$auth->requireRole(['super_admin', 'admin']);
$pdo = getDBConnection();
$username        = trim($_POST['username'] ?? '');
$password        = $_POST['password'] ?? '';
$email           = trim($_POST['email'] ?? '');
$full_name       = trim($_POST['full_name'] ?? '');
$role            = $_POST['role'] ?? 'viewer';
$assigned_domain = trim($_POST['assigned_domain'] ?? '') ?: null;
$allowed_roles   = ['super_admin', 'admin', 'domain_admin', 'viewer'];
if (!in_array($role, $allowed_roles)) $role = 'viewer';
if (empty($username) || empty($password)) {
    echo json_encode(['success' => false, 'error' => 'Username and password required']);
    exit;
}
try {
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare(
        "INSERT INTO users (username, password, email, full_name, role, assigned_domain, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$username, $hashed, $email, $full_name, $role, $assigned_domain, $_SESSION['user_id']]);
    echo json_encode(['success' => true, 'message' => 'User created successfully']);
} catch(Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
