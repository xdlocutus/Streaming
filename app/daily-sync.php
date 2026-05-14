<?php
declare(strict_types=1);
require __DIR__ . '/services.php';
$config = require __DIR__ . '/config.php';
$cache = new FileCache();
$client = new ApiClient();
$tmdb = new TmdbService($config['tmdb'], $cache, $client);
$trakt = new TraktService($config['trakt'], $cache, $client);
foreach (['movie', 'tv'] as $type) { $tmdb->trending($type, 'en-US'); }
foreach (['movies', 'shows'] as $type) { $trakt->trending($type); }
echo '[' . gmdate('c') . "] Daily metadata sync completed\n";
