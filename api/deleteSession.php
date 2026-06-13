<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../assets/config/db_config.php';
require_once __DIR__ . '/../assets/auth/auth.php';

$auth = new Auth();
$auth->requireLogin();

function buildUpstreamUrl($path) {
    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $port = isset($_SERVER['SERVER_PORT']) ? (int) $_SERVER['SERVER_PORT'] : null;
    $isDefaultPort = !$port || (!$isHttps && $port === 80) || ($isHttps && $port === 443);
    $hostWithPort = $isDefaultPort ? $host : $host . ':' . $port;

    return $scheme . '://' . $hostWithPort . '/' . ltrim($path, '/');
}

function fetchJsonFromUpstream($path, $timeout = 5) {
    $ch = curl_init(buildUpstreamUrl($path));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Failed to fetch ' . $path . ': ' . ($error ?: 'Request failed'));
    }

    if ($httpCode >= 400) {
        throw new RuntimeException('Failed to fetch ' . $path . ': upstream returned HTTP ' . $httpCode);
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException('Failed to fetch ' . $path . ': invalid upstream JSON response');
    }

    return $data;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$socketId = trim((string) ($payload['socketId'] ?? ''));

if ($socketId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'socketId is required']);
    exit;
}

try {
    $sessionResponse = fetchJsonFromUpstream('/getSession?socketId=' . rawurlencode($socketId));
    $session = $sessionResponse['data'] ?? null;

    if (!$sessionResponse['success'] || !is_array($session)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Session not found']);
        exit;
    }

    $domain = $session['domain'] ?? '';
    if (!$auth->canAccessDomain($domain)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }

    $response = fetchJsonFromUpstream('/deleteSession?socketId=' . rawurlencode($socketId));

    if (!empty($response['success'])) {
        $auth->logActivity(
            'delete_session',
            'Deleted session ' . $socketId . ' for domain ' . $domain,
            'delete'
        );
    }

    echo json_encode($response);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
