<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/services.php';
$config = require __DIR__ . '/config.php';
$action = $_GET['action'] ?? 'start';
$tokenPath = __DIR__ . '/../data/trakt-token.json';
if ($action === 'callback') {
    if (!hash_equals($_SESSION['trakt_oauth_state'] ?? '', (string) ($_GET['state'] ?? ''))) {
        http_response_code(400);
        echo 'Invalid Trakt OAuth state.';
        exit;
    }
    $code = (string) ($_GET['code'] ?? '');
    if ($code === '') {
        http_response_code(400);
        echo 'Missing Trakt OAuth code.';
        exit;
    }
    $trakt = new TraktService($config['trakt'], create_cache($config), new ApiClient());
    $token = $trakt->exchangeCode($code);
    if (isset($token['error'])) {
        http_response_code(502);
        echo 'Trakt token exchange failed: ' . htmlspecialchars((string) $token['error'], ENT_QUOTES);
        exit;
    }
    if (!is_dir(dirname($tokenPath))) {
        mkdir(dirname($tokenPath), 0775, true);
    }
    $token['stored_at'] = gmdate('c');
    file_put_contents($tokenPath, json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    header('Location: ../index.php?page=settings&trakt=connected', true, 302);
    exit;
}
if ($config['trakt']['client_id'] === '') {
    http_response_code(503);
    echo 'Set TRAKT_CLIENT_ID and TRAKT_CLIENT_SECRET before starting OAuth.';
    exit;
}
$_SESSION['trakt_oauth_state'] = bin2hex(random_bytes(16));
$params = http_build_query([
    'response_type' => 'code',
    'client_id' => $config['trakt']['client_id'],
    'redirect_uri' => $config['trakt']['redirect_uri'],
    'state' => $_SESSION['trakt_oauth_state'],
]);
header('Location: https://trakt.tv/oauth/authorize?' . $params, true, 302);
