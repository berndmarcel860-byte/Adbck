<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../assets/config/db_config.php';
require_once __DIR__ . '/../assets/auth/auth.php';

$auth = new Auth();
$auth->requireLogin();

function fetchJsonFromUpstream($path, $timeout = 5) {
    $ch = curl_init($path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException($error ?: 'Request failed');
    }

    if ($httpCode >= 400) {
        throw new RuntimeException('Upstream returned HTTP ' . $httpCode);
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid upstream JSON response');
    }

    return $data;
}

try {
    $sessionsResponse = fetchJsonFromUpstream('/getAllSessions');
    $sessions = array_values($sessionsResponse['data'] ?? []);
    $filteredSessions = $auth->filterSessionsByAccess($sessions);

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
