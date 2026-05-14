<?php
declare(strict_types=1);
require __DIR__ . '/services.php';
$config = require __DIR__ . '/config.php';
$cache = create_cache($config);
$client = new ApiClient();
$tmdb = new TmdbService($config['tmdb'], $cache, $client);
$trakt = new TraktService($config['trakt'], $cache, $client);
$providers = new ProviderRepository();
$stremio = new StremioAddonClient($client);
$started = microtime(true);
foreach (['movie', 'tv'] as $type) {
    $tmdb->trending($type, 'en-US');
    $tmdb->genres($type, 'en-US');
    foreach (['popularity.desc', 'vote_average.desc', 'primary_release_date.desc'] as $sort) {
        $tmdb->discover($type, ['language' => 'en-US', 'sort_by' => $sort, 'page' => 1]);
    }
}
foreach (['movies', 'shows'] as $type) {
    $trakt->trending($type);
}
foreach ($providers->all() as $provider) {
    $providers->upsert(array_merge($provider, ['health' => $stremio->health($provider)]));
}
echo json_encode(['completed_at' => gmdate('c'), 'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'providers_checked' => count($providers->all()), 'cache' => $cache->stats()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
