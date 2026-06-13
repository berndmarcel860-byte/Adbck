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

try {
    $sessionsResponse = fetchJsonFromUpstream('/getAllSessions');
    $sessions = array_values($sessionsResponse['data'] ?? []);
    $filteredSessions = $auth->filterSessionsByAccess($sessions);

    foreach ($filteredSessions as &$session) {
        $session['canSendCommands'] = $auth->canSendCommandsForDomain($session['domain'] ?? '');
    }
    unset($session);

    $allowedSocketIds = [];
    foreach ($filteredSessions as $session) {
        if (!empty($session['socketId'])) {
            $allowedSocketIds[$session['socketId']] = true;
        }
    }

    $onlineClients = [];
    try {
        $clientsResponse = fetchJsonFromUpstream('/clients', 3);
        $clients = $clientsResponse['clients'] ?? [];

        foreach ($clients as $client) {
            if (!empty($client['socketId']) && isset($allowedSocketIds[$client['socketId']])) {
                $onlineClients[] = $client;
            }
        }
    } catch (Throwable $e) {
        $onlineClients = [];
    }

    echo json_encode([
        'success' => true,
        'sessions' => array_values($filteredSessions),
        'onlineClients' => array_values($onlineClients)
    ]);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
