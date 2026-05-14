<?php
declare(strict_types=1);
require __DIR__ . '/services.php';
$config = require __DIR__ . '/config.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json');
    header('Cache-Control: public, max-age=120, stale-while-revalidate=86400');
}

function request_input(): array
{
    if (PHP_SAPI === 'cli') {
        parse_str(implode('&', array_slice($_SERVER['argv'] ?? [], 1)), $args);
        return $args;
    }
    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    return array_merge($_GET, $_POST, $body);
}

$input = request_input();
$cache = new FileCache();
$client = new ApiClient();
$tmdb = new TmdbService($config['tmdb'], $cache, $client);
$trakt = new TraktService($config['trakt'], $cache, $client);
$providerRepository = new ProviderRepository();
$stremioClient = new StremioAddonClient($client);
$aggregator = new StreamAggregator($providerRepository, $stremioClient);
$action = $input['action'] ?? 'health';

$result = match ($action) {
    'tmdb-trending' => $tmdb->trending($input['type'] ?? 'movie', $input['language'] ?? 'en-US'),
    'trakt-trending' => $trakt->trending($input['type'] ?? 'movies'),
    'providers' => ['providers' => $providerRepository->all()],
    'provider-add' => $aggregator->addProvider((string) ($input['manifest_url'] ?? ''), (int) ($input['priority'] ?? 100), filter_var($input['enabled'] ?? true, FILTER_VALIDATE_BOOL)),
    'provider-remove' => ['ok' => $providerRepository->remove((string) ($input['id'] ?? ''))],
    'provider-enable' => ['ok' => $providerRepository->setEnabled((string) ($input['id'] ?? ''), filter_var($input['enabled'] ?? true, FILTER_VALIDATE_BOOL))],
    'provider-priority' => ['ok' => $providerRepository->setPriority((string) ($input['id'] ?? ''), (int) ($input['priority'] ?? 100))],
    'provider-test' => $aggregator->testProvider((string) ($input['id'] ?? '')),
    'streams' => $aggregator->streams((string) ($input['type'] ?? 'movie'), (string) ($input['id'] ?? '')),
    'health' => [
        'ok' => true,
        'services' => [
            'tmdb' => $config['tmdb']['api_key'] !== '',
            'trakt' => $config['trakt']['client_id'] !== '',
            'imdb_ids' => true,
            'stremio_providers' => count($providerRepository->all()),
            'cache' => is_writable(__DIR__ . '/../data/cache'),
        ],
    ],
    default => ['error' => 'unknown_action'],
};

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
