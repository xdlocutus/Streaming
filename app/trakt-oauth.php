<?php
declare(strict_types=1);
$config = require __DIR__ . '/config.php';
$action = $_GET['action'] ?? 'start';
if ($action === 'callback') {
    echo 'Trakt OAuth callback received. Exchange the code server-side, store encrypted tokens, then sync watchlists, history, and scrobble progress.';
    exit;
}
if ($config['trakt']['client_id'] === '') {
    http_response_code(503);
    echo 'Set TRAKT_CLIENT_ID and TRAKT_CLIENT_SECRET before starting OAuth.';
    exit;
}
$params = http_build_query([
    'response_type' => 'code',
    'client_id' => $config['trakt']['client_id'],
    'redirect_uri' => $config['trakt']['redirect_uri'],
]);
header('Location: https://trakt.tv/oauth/authorize?' . $params, true, 302);
