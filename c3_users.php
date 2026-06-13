<?php
// c3_users.php - User Management with Subdomain Support
session_start();
require_once __DIR__ . '/assets/config/db_config.php';
require_once __DIR__ . '/assets/auth/auth.php';
$auth = new Auth();
$auth->requireRole(['super_admin', 'admin']);

$pdo = getDBConnection();
$message = '';
$error = '';

// Node server base URL (must match PORT in 0.js)
define('NODE_SERVER_URL', 'http://localhost:8087');

/**
 * Tell the node server about a domain's Telegram config so it can route
 * notifications to the right bot/channel immediately (without a restart).
 */
function notifyNodeTelegramConfig(string $domain, string $botToken, string $chatId): void {
    $ch = curl_init(NODE_SERVER_URL . '/setDomainTelegram');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'domain'    => $domain,
        'bot_token' => $botToken,
        'chat_id'   => $chatId,
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_exec($ch);
    curl_close($ch);
}

// Handle user creation/update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_user') {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $email = $_POST['email'] ?? '';
        $full_name = $_POST['full_name'] ?? '';
        $role = $_POST['role'] ?? 'viewer';
        $assigned_domain = $_POST['assigned_domain'] ?? null;
        $telegram_bot_token = trim($_POST['telegram_bot_token'] ?? '') ?: null;
        $telegram_chat_id   = trim($_POST['telegram_chat_id']   ?? '') ?: null;
        
        if (empty($username) || empty($password)) {
            $error = 'Username and password are required';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            try {
                $stmt = $pdo->prepare("INSERT INTO users (username, password, email, full_name, role, assigned_domain, telegram_bot_token, telegram_chat_id, created_by) 
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$username, $hashed, $email, $full_name, $role, $assigned_domain, $telegram_bot_token, $telegram_chat_id, $_SESSION['user_id']]);
                $message = "User {$username} created successfully";
                $auth->logActivity('create_user', "Created user: {$username}");
                // Propagate Telegram config to node server immediately
                if ($assigned_domain && $telegram_bot_token && $telegram_chat_id) {
                    notifyNodeTelegramConfig($assigned_domain, $telegram_bot_token, $telegram_chat_id);
                }
            } catch(PDOException $e) {
                $error = "Failed to create user: " . $e->getMessage();
            }
        }
    }
    
    if ($action === 'update_user') {
        $user_id = $_POST['user_id'];
        $email = $_POST['email'] ?? '';
        $full_name = $_POST['full_name'] ?? '';
        $role = $_POST['role'] ?? 'viewer';
        $assigned_domain = $_POST['assigned_domain'] ?? null;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $telegram_bot_token = trim($_POST['telegram_bot_token'] ?? '') ?: null;
        $telegram_chat_id   = trim($_POST['telegram_chat_id']   ?? '') ?: null;
        
        try {
            $stmt = $pdo->prepare("UPDATE users SET email = ?, full_name = ?, role = ?, assigned_domain = ?, is_active = ?, telegram_bot_token = ?, telegram_chat_id = ? WHERE id = ?");
            $stmt->execute([$email, $full_name, $role, $assigned_domain, $is_active, $telegram_bot_token, $telegram_chat_id, $user_id]);
            $message = "User updated successfully";
            $auth->logActivity('update_user', "Updated user ID: {$user_id}");
            // Propagate Telegram config to node server immediately
            if ($assigned_domain && $telegram_bot_token && $telegram_chat_id) {
                notifyNodeTelegramConfig($assigned_domain, $telegram_bot_token, $telegram_chat_id);
            }
        } catch(PDOException $e) {
            $error = "Failed to update user: " . $e->getMessage();
        }
    }
    
    if ($action === 'add_permission') {
        $user_id = $_POST['user_id'];
        $domain_id = $_POST['domain_id'];
        $can_view = isset($_POST['can_view']) ? 1 : 0;
        $can_edit = isset($_POST['can_edit']) ? 1 : 0;
        $can_delete = isset($_POST['can_delete']) ? 1 : 0;
        $can_send_commands = isset($_POST['can_send_commands']) ? 1 : 0;
        
        try {
            $stmt = $pdo->prepare("INSERT INTO domain_permissions (user_id, domain_id, can_view, can_edit, can_delete, can_send_commands, assigned_by) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?) 
                                   ON DUPLICATE KEY UPDATE 
                                   can_view = VALUES(can_view), can_edit = VALUES(can_edit), 
                                   can_delete = VALUES(can_delete), can_send_commands = VALUES(can_send_commands)");
            $stmt->execute([$user_id, $domain_id, $can_view, $can_edit, $can_delete, $can_send_commands, $_SESSION['user_id']]);
            $message = "Permission added successfully";
        } catch(PDOException $e) {
            $error = "Failed to add permission: " . $e->getMessage();
        }
    }
    
    if ($action === 'delete_user') {
        $user_id = $_POST['user_id'];
        if ($user_id != $_SESSION['user_id']) {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $message = "User deleted successfully";
        } else {
            $error = "Cannot delete your own account";
        }
    }
}

// Get all users
$users = $pdo->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll();

// Get all domains
$domains = $pdo->query("SELECT * FROM domains ORDER BY domain_name")->fetchAll();

// Get domain permissions for each user
$permissions = [];
foreach ($users as $user) {
    $stmt = $pdo->prepare("SELECT dp.*, d.domain_name FROM domain_permissions dp 
                           JOIN domains d ON dp.domain_id = d.id 
                           WHERE dp.user_id = ?");
    $stmt->execute([$user['id']]);
    $permissions[$user['id']] = $stmt->fetchAll();
}

// Get all unique domains from sessions (for auto-detection)
$ch = curl_init('/getAllSessions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 2);
$resp = curl_exec($ch);
curl_close($ch);
$sessionsData = json_decode($resp, true);
$sessionDomains = [];
if (isset($sessionsData['data'])) {
    foreach ($sessionsData['data'] as $session) {
        $domain = $session['domain'] ?? null;
        if ($domain && !in_array($domain, $sessionDomains)) {
            $sessionDomains[] = $domain;
        }
    }
    sort($sessionDomains);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { background: white; border-radius: 15px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card { border-radius: 15px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .card-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 15px 15px 0 0 !important; padding: 15px 20px; }
        .badge-super_admin { background: #ef4444; }
        .badge-admin { background: #f59e0b; }
        .badge-domain_admin { background: #10b981; }
        .badge-viewer { background: #3b82f6; }
        .domain-badge { background: #e9ecef; padding: 2px 8px; border-radius: 12px; font-size: 11px; margin: 2px; display: inline-block; }
        .subdomain-badge { background: #d4edda; color: #155724; }
        .wildcard-info { font-size: 11px; color: #666; margin-left: 5px; }
        .btn-action { padding: 4px 8px; font-size: 11px; margin: 2px; }
        table { width: 100%; }
        th { background: #f8f9fa; padding: 12px; }
        td { padding: 12px; border-bottom: 1px solid #e9ecef; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header d-flex justify-content-between align-items-center">
            <div>
                <h2><i class="fas fa-users"></i> User Management</h2>
                <p class="text-muted mb-0">Manage users and their domain access (including subdomains)</p>
            </div>
            <a href="c3_admin_dashboard.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <!-- Create User Form -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-user-plus"></i> Create New User</h5>
            </div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <input type="hidden" name="action" value="create_user">
                    <div class="col-md-2"><input type="text" name="username" class="form-control" placeholder="Username" required></div>
                    <div class="col-md-2"><input type="password" name="password" class="form-control" placeholder="Password" required></div>
                    <div class="col-md-2"><input type="email" name="email" class="form-control" placeholder="Email"></div>
                    <div class="col-md-2"><input type="text" name="full_name" class="form-control" placeholder="Full Name"></div>
                    <div class="col-md-2">
                        <select name="role" class="form-select">
                            <option value="viewer">Viewer</option>
                            <option value="domain_admin">Domain Admin</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="assigned_domain" class="form-select">
                            <option value="">No domain (admin)</option>
                            <?php foreach ($domains as $domain): ?>
                                <option value="<?php echo htmlspecialchars($domain['domain_name']); ?>">
                                    <?php echo htmlspecialchars($domain['domain_name']); ?>
                                    <?php if ($domain['is_wildcard']): ?> (includes all subdomains)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- Telegram Notification Config -->
                    <div class="col-12">
                        <hr class="my-1">
                        <small class="text-muted"><i class="fab fa-telegram"></i> Telegram notifications — optional. If set, all domain/subdomain session alerts go only to this bot &amp; channel.</small>
                    </div>
                    <div class="col-md-5"><input type="text" name="telegram_bot_token" class="form-control" placeholder="Telegram Bot Token (e.g. 123456:ABC...)"></div>
                    <div class="col-md-3"><input type="text" name="telegram_chat_id" class="form-control" placeholder="Telegram Chat/Channel ID (e.g. -1001...)"></div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary">Create User</button>
                        <small class="text-muted ms-2">Bot token &amp; chat ID are optional</small>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Users List -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Users</h5>
            </div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead>
                        <tr><th>ID</th><th>Username</th><th>Name</th><th>Email</th><th>Role</th><th>Assigned Domain</th><th>Telegram Bot</th><th>Created</th><th>Last Login</th><th>Access</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo $user['id']; ?></td>
                            <td><strong><?php echo htmlspecialchars($user['username']); ?></strong></td>
                            <td><?php echo htmlspecialchars($user['full_name'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($user['email'] ?? '-'); ?></td>
                            <td><span class="badge badge-<?php echo $user['role']; ?>"><?php echo $user['role']; ?></span></td>
                            <td>
                                <?php if ($user['assigned_domain']): ?>
                                    <span class="domain-badge">
                                        <i class="fas fa-globe"></i> <?php echo htmlspecialchars($user['assigned_domain']); ?>
                                        <small class="wildcard-info">(includes all subdomains)</small>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($user['telegram_bot_token']) && !empty($user['telegram_chat_id'])): ?>
                                    <span class="badge bg-info text-dark" title="Bot Token: <?php echo htmlspecialchars($user['telegram_bot_token']); ?>">
                                        <i class="fab fa-telegram"></i>
                                        <?php
                                            // Show only first 10 chars of token for security
                                            $shortToken = substr($user['telegram_bot_token'], 0, 10) . '...';
                                            echo htmlspecialchars($shortToken);
                                        ?>
                                    </span>
                                    <small class="d-block text-muted"><?php echo htmlspecialchars($user['telegram_chat_id']); ?></small>
                                <?php else: ?>
                                    <span class="text-muted">Default bot</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></td>
                            <td><?php echo $user['last_login'] ? date('Y-m-d H:i', strtotime($user['last_login'])) : '-'; ?></td>
                            <td>
                                <?php if ($user['role'] === 'domain_admin' && $user['assigned_domain']): ?>
                                    <small class="text-success">
                                        <i class="fas fa-check-circle"></i> Auto: All subdomains of <?php echo htmlspecialchars($user['assigned_domain']); ?>
                                    </small>
                                <?php elseif (isset($permissions[$user['id']])): ?>
                                    <?php foreach ($permissions[$user['id']] as $perm): ?>
                                        <span class="domain-badge"><?php echo htmlspecialchars($perm['domain_name']); ?></span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="text-muted">No access</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-info" onclick="editUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>', '<?php echo htmlspecialchars($user['email']); ?>', '<?php echo htmlspecialchars($user['full_name']); ?>', '<?php echo $user['role']; ?>', '<?php echo htmlspecialchars($user['assigned_domain']); ?>', <?php echo $user['is_active']; ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-sm btn-primary" onclick="showPermissions(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')">
                                    <i class="fas fa-key"></i>
                                </button>
                                <?php if ($user['id'] != 1 && $user['id'] != $_SESSION['user_id']): ?>
                                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this user?')">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-user-edit"></i> Edit User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_user">
                        <input type="hidden" name="user_id" id="edit_user_id">
                        <div class="mb-3"><label>Username</label><input type="text" id="edit_username" class="form-control" readonly></div>
                        <div class="mb-3"><label>Email</label><input type="email" name="email" id="edit_email" class="form-control"></div>
                        <div class="mb-3"><label>Full Name</label><input type="text" name="full_name" id="edit_full_name" class="form-control"></div>
                        <div class="mb-3"><label>Role</label>
                            <select name="role" id="edit_role" class="form-select">
                                <option value="viewer">Viewer</option>
                                <option value="domain_admin">Domain Admin</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <div class="mb-3"><label>Assigned Domain (for Domain Admin)</label>
                            <select name="assigned_domain" id="edit_assigned_domain" class="form-select">
                                <option value="">None</option>
                                <?php foreach ($domains as $domain): ?>
                                    <option value="<?php echo htmlspecialchars($domain['domain_name']); ?>">
                                        <?php echo htmlspecialchars($domain['domain_name']); ?>
                                        <?php if ($domain['is_wildcard']): ?> (includes subdomains)<?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3"><div class="form-check"><input type="checkbox" name="is_active" id="edit_is_active" class="form-check-input"> Active</div></div>
                        <hr>
                        <p class="text-muted small"><i class="fab fa-telegram"></i> Telegram Notifications (optional)</p>
                        <div class="mb-3"><label>Bot Token</label><input type="text" name="telegram_bot_token" id="edit_telegram_bot_token" class="form-control" placeholder="e.g. 123456:ABC-DEF..."></div>
                        <div class="mb-3"><label>Chat / Channel ID</label><input type="text" name="telegram_chat_id" id="edit_telegram_chat_id" class="form-control" placeholder="e.g. -1001234567890"></div>
                        <small class="text-muted">Leave blank to use the server's default bot. When set, all alerts for this user's assigned domain go only to this bot &amp; channel.</small>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Permissions Modal -->
    <div class="modal fade" id="permissionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-key"></i> Domain Permissions</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_permission">
                        <input type="hidden" name="user_id" id="perm_user_id">
                        <div class="mb-3"><label>User: <strong id="perm_username"></strong></label></div>
                        <div class="mb-3">
                            <label>Domain</label>
                            <select name="domain_id" class="form-select" required>
                                <option value="">Select domain...</option>
                                <?php foreach ($domains as $domain): ?>
                                    <option value="<?php echo $domain['id']; ?>">
                                        <?php echo htmlspecialchars($domain['domain_name']); ?>
                                        <?php if ($domain['is_wildcard']): ?> (includes all subdomains)<?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">If wildcard domain selected, user will see all subdomains automatically</small>
                        </div>
                        <div class="mb-3">
                            <label>Permissions:</label>
                            <div class="form-check"><input type="checkbox" name="can_view" class="form-check-input" checked> View sessions</div>
                            <div class="form-check"><input type="checkbox" name="can_edit" class="form-check-input"> Edit data</div>
                            <div class="form-check"><input type="checkbox" name="can_delete" class="form-check-input"> Delete sessions</div>
                            <div class="form-check"><input type="checkbox" name="can_send_commands" class="form-check-input"> Send commands</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Permissions</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
    function editUser(id, username, email, fullName, role, assignedDomain, isActive) {
        document.getElementById('edit_user_id').value = id;
        document.getElementById('edit_username').value = username;
        document.getElementById('edit_email').value = email || '';
        document.getElementById('edit_full_name').value = fullName || '';
        document.getElementById('edit_role').value = role;
        document.getElementById('edit_assigned_domain').value = assignedDomain || '';
        document.getElementById('edit_is_active').checked = isActive == 1;
        new bootstrap.Modal(document.getElementById('editUserModal')).show();
    }
    
    function showPermissions(userId, username) {
        document.getElementById('perm_user_id').value = userId;
        document.getElementById('perm_username').textContent = username;
        new bootstrap.Modal(document.getElementById('permissionModal')).show();
    }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>