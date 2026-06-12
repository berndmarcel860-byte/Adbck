<?php
session_start();
require_once __DIR__ . '/assets/config/db_config.php';
require_once __DIR__ . '/assets/auth/auth.php';

$auth = new Auth();
$auth->requireLogin();

$currentUser = $auth->getCurrentUser();
$accessibleDomains = $auth->getAccessibleDomains();
$isSuperAdmin = ($_SESSION['role'] === 'super_admin');
$isAdmin = ($_SESSION['role'] === 'admin');
$isDomainAdmin = ($_SESSION['role'] === 'domain_admin');
$assignedMainDomain = $_SESSION['assigned_domain'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo htmlspecialchars($_SESSION['username']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root {
            --primary: #667eea;
            --secondary: #764ba2;
            --success: #10b981;
            --danger: #ef4444;
            --dark: #1a1a2e;
        }
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100%;
            background: linear-gradient(180deg, var(--dark) 0%, #16213e 100%);
            color: white; z-index: 1000;
        }
        .sidebar-header { padding: 25px 20px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-menu { padding: 20px 0; }
        .menu-item {
            padding: 12px 25px; display: flex; align-items: center; gap: 12px;
            cursor: pointer; transition: all 0.3s; border-left: 3px solid transparent;
        }
        .menu-item:hover, .menu-item.active {
            background: rgba(255,255,255,0.1);
            border-left-color: var(--primary);
        }
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar {
            background: white; border-radius: 15px; padding: 15px 25px;
            margin-bottom: 25px; display: flex; justify-content: space-between;
            align-items: center; box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 25px; }
        .stat-card {
            background: white; border-radius: 15px; padding: 20px; text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05); transition: transform 0.2s;
        }
        .stat-card:hover { transform: translateY(-3px); }
        .stat-number { font-size: 32px; font-weight: bold; }
        .loading { text-align: center; padding: 40px; }
        .spinner { width: 40px; height: 40px; border: 3px solid #f3f3f3; border-top: 3px solid var(--primary); border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 15px; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @media (max-width: 768px) {
            .sidebar { width: 70px; }
            .sidebar-header h3, .menu-item span { display: none; }
            .main-content { margin-left: 70px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <h3><i class="fas fa-chart-line"></i> SessionHub</h3>
        </div>
        <div class="sidebar-menu">
            <div class="menu-item active" data-page="dashboard"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></div>
            <div class="menu-item" data-page="sessions"><i class="fas fa-database"></i><span>Sessions</span></div>
            <div class="menu-item" data-page="profile"><i class="fas fa-user"></i><span>Profile</span></div>
        </div>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div>
                <h2 id="pageTitle">Dashboard</h2>
                <p id="pageDesc">Welcome, <?php echo htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']); ?></p>
            </div>
            <div class="d-flex gap-3">
                <div><i class="fas fa-user-circle"></i> <?php echo $_SESSION['role']; ?></div>
                <a href="c3_logout.php" class="text-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
        
        <div id="pageContent"><div class="loading"><div class="spinner"></div>Loading...</div></div>
    </div>

    <script>
    let currentPage = 'dashboard';
    
    function loadPage(page) {
        currentPage = page;
        $('.menu-item').removeClass('active');
        $(`.menu-item[data-page="${page}"]`).addClass('active');
        $('#pageContent').html('<div class="loading"><div class="spinner"></div>Loading...</div>');
        
        if (page === 'dashboard') loadDashboard();
        else if (page === 'sessions') loadSessions();
        else if (page === 'profile') loadProfile();
    }
    
    async function loadDashboard() {
        try {
            const response = await fetch('/getAllSessions');
            const data = await response.json();
            const sessions = Object.values(data.data || {});
            
            $('#pageContent').html(`
                <div class="stats-grid">
                    <div class="stat-card"><div class="stat-number">${sessions.length}</div><div class="stat-label">Total Sessions</div></div>
                    <div class="stat-card"><div class="stat-number">0</div><div class="stat-label">Online Now</div></div>
                    <div class="stat-card"><div class="stat-number">0</div><div class="stat-label">Profiles</div></div>
                    <div class="stat-card"><div class="stat-number">0</div><div class="stat-label">Logins</div></div>
                </div>
                <div class="card"><div class="card-header bg-white fw-bold">Welcome to Dashboard</div><div class="card-body"><p>Select a menu option to get started.</p></div></div>
            `);
        } catch(e) {
            $('#pageContent').html('<div class="alert alert-danger">Error loading dashboard</div>');
        }
    }
    
    function loadSessions() {
        $('#pageContent').html('<div class="loading"><div class="spinner"></div>Loading sessions...</div>');
        fetch('/getAllSessions').then(r => r.json()).then(data => {
            const sessions = Object.values(data.data || {});
            let html = `<div class="card"><div class="card-header bg-primary text-white">Recent Sessions (${sessions.length})</div><div class="list-group list-group-flush">`;
            for (const session of sessions.slice(0, 20)) {
                html += `<div class="list-group-item"><div class="d-flex justify-content-between"><div><code>${session.socketId.substring(0, 30)}...</code><br><small>${session.domain || 'unknown'}</small></div><small>${new Date(session.created_at).toLocaleString()}</small></div></div>`;
            }
            html += `</div></div>`;
            $('#pageContent').html(html);
        }).catch(() => $('#pageContent').html('<div class="alert alert-danger">Error loading sessions</div>'));
    }
    
    function loadProfile() {
        $('#pageContent').html(`
            <div class="card"><div class="card-header bg-primary text-white"><h5>My Profile</h5></div>
            <div class="card-body">
                <div class="row"><div class="col-md-6"><label>Username</label><input type="text" class="form-control" value="<?php echo htmlspecialchars($_SESSION['username']); ?>" readonly></div>
                <div class="col-md-6"><label>Role</label><input type="text" class="form-control" value="<?php echo $_SESSION['role']; ?>" readonly></div>
                </div>
            </div></div>
        `);
    }
    
    $(document).ready(function() {
        $('.menu-item').click(function() { loadPage($(this).data('page')); });
        loadPage('dashboard');
    });
    </script>
</body>
</html>