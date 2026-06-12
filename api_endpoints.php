<?php
// API Endpoints for AJAX calls
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/assets/auth/auth.php';
require_once __DIR__ . '/assets/config/db_config.php';

$pdo = getDBConnection();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Initialize auth for role checking
$auth = new Auth();

switch($action) {
    case 'getUsers':
        $auth->requireRole(['super_admin', 'admin']);
        $stmt = $pdo->query("SELECT * FROM users ORDER BY created_at DESC");
        echo json_encode(['success' => true, 'users' => $stmt->fetchAll()]);
        break;
        
    case 'getUserActivity':
        $auth->requireRole(['super_admin', 'admin']);
        $stmt = $pdo->query("SELECT ua.*, u.username FROM user_activity ua LEFT JOIN users u ON ua.user_id = u.id ORDER BY ua.created_at DESC LIMIT 200");
        echo json_encode(['success' => true, 'activities' => $stmt->fetchAll()]);
        break;
        
    case 'getNotifications':
        $userId = $_SESSION['user_id'] ?? 0;
        $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id IS NULL OR user_id = ? ORDER BY created_at DESC LIMIT 100");
        $stmt->execute([$userId]);
        echo json_encode(['success' => true, 'notifications' => $stmt->fetchAll()]);
        break;
        
    case 'getNotificationCount':
        $userId = $_SESSION['user_id'] ?? 0;
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE (user_id IS NULL OR user_id = ?) AND is_read = 0");
        $stmt->execute([$userId]);
        echo json_encode(['success' => true, 'count' => $stmt->fetchColumn()]);
        break;
        
    case 'sendNotification':
        $auth->requireRole(['super_admin', 'admin']);
        $userId = $_POST['user_id'] ?? null;
        $title = $_POST['title'] ?? '';
        $message = $_POST['message'] ?? '';
        $type = $_POST['type'] ?? 'info';
        
        if ($userId === 'all') {
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, created_by) SELECT id, ?, ?, ?, ? FROM users");
            $stmt->execute([$title, $message, $type, $_SESSION['user_id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, created_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $title, $message, $type, $_SESSION['user_id']]);
        }
        echo json_encode(['success' => true]);
        break;
        
    case 'markNotificationRead':
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        echo json_encode(['success' => true]);
        break;
        
    case 'changePassword':
        $auth->requireLogin();
        $current = $_POST['current'] ?? '';
        $new = $_POST['new'] ?? '';
        
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        
        if (password_verify($current, $user['password'])) {
            $hashed = password_hash($new, PASSWORD_DEFAULT);
            $update = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $update->execute([$hashed, $_SESSION['user_id']]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Current password incorrect']);
        }
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
?>