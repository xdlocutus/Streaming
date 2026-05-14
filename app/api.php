<?php
declare(strict_types=1);
require __DIR__ . '/services.php';
$config = require __DIR__ . '/config.php';
header('Content-Type: application/json');
header('Cache-Control: public, max-age=120, stale-while-revalidate=86400');
$cache = new FileCache();
$client = new ApiClient();
$tmdb = new TmdbService($config['tmdb'], $cache, $client);
$trakt = new TraktService($config['trakt'], $cache, $client);
$action = $_GET['action'] ?? 'health';
$result = match ($action) {
    'tmdb-trending' => $tmdb->trending($_GET['type'] ?? 'movie', $_GET['language'] ?? 'en-US'),
    'trakt-trending' => $trakt->trending($_GET['type'] ?? 'movies'),
    'health' => ['ok' => true, 'services' => ['tmdb' => $config['tmdb']['api_key'] !== '', 'trakt' => $config['trakt']['client_id'] !== '', 'cache' => is_writable(__DIR__ . '/../data/cache')]],
    default => ['error' => 'unknown_action'],
};
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
