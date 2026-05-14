<?php
declare(strict_types=1);

interface CacheStore
{
    public function remember(string $key, int $ttl, callable $producer): array;
    public function get(string $key): ?array;
    public function put(string $key, array $value, int $ttl): array;
    public function delete(string $key): bool;
    public function stats(): array;
}

final class FileCache implements CacheStore
{
    public function __construct(private string $directory = __DIR__ . '/../data/cache')
    {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }
    }

    public function remember(string $key, int $ttl, callable $producer): array
    {
        $cached = $this->getFresh($key, $ttl);
        if ($cached !== null) {
            return $cached;
        }
        $value = $producer();
        return $this->put($key, is_array($value) ? $value : [], $ttl);
    }

    public function get(string $key): ?array
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }
        return json_decode((string) file_get_contents($path), true) ?: null;
    }

    public function put(string $key, array $value, int $ttl): array
    {
        $payload = ['stored_at' => time(), 'expires_at' => time() + $ttl, 'value' => $value];
        file_put_contents($this->path($key), json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $value;
    }

    public function delete(string $key): bool
    {
        $path = $this->path($key);
        return is_file($path) ? unlink($path) : false;
    }

    public function stats(): array
    {
        $files = glob($this->directory . '/*.json') ?: [];
        $bytes = array_sum(array_map('filesize', $files));
        $expired = 0;
        foreach ($files as $file) {
            $payload = json_decode((string) file_get_contents($file), true) ?: [];
            if (($payload['expires_at'] ?? PHP_INT_MAX) < time()) {
                $expired++;
            }
        }
        return ['driver' => 'file', 'entries' => count($files), 'expired' => $expired, 'bytes' => $bytes, 'writable' => is_writable($this->directory)];
    }

    private function getFresh(string $key, int $ttl): ?array
    {
        $payload = $this->get($key);
        if ($payload === null) {
            return null;
        }
        if (isset($payload['value'], $payload['expires_at'])) {
            return ((int) $payload['expires_at'] > time()) ? (array) $payload['value'] : null;
        }
        $path = $this->path($key);
        return is_file($path) && filemtime($path) + $ttl > time() ? $payload : null;
    }

    private function path(string $key): string
    {
        return $this->directory . '/' . hash('sha256', $key) . '.json';
    }
}


final class RedisCache implements CacheStore
{
    private Redis $redis;
    private string $prefix = 'astra:';

    public function __construct(string $url)
    {
        $parts = parse_url($url);
        $this->redis = new Redis();
        $this->redis->connect($parts['host'] ?? '127.0.0.1', (int) ($parts['port'] ?? 6379), 1.5);
        if (isset($parts['pass'])) {
            $this->redis->auth($parts['pass']);
        }
        if (isset($parts['path']) && trim($parts['path'], '/') !== '') {
            $this->redis->select((int) trim($parts['path'], '/'));
        }
    }

    public function remember(string $key, int $ttl, callable $producer): array
    {
        $cached = $this->get($key);
        if ($cached !== null) {
            return $cached;
        }
        $value = $producer();
        return $this->put($key, is_array($value) ? $value : [], $ttl);
    }

    public function get(string $key): ?array
    {
        $value = $this->redis->get($this->prefix . hash('sha256', $key));
        return $value ? (json_decode($value, true) ?: null) : null;
    }

    public function put(string $key, array $value, int $ttl): array
    {
        $this->redis->setex($this->prefix . hash('sha256', $key), $ttl, json_encode($value, JSON_UNESCAPED_SLASHES));
        return $value;
    }

    public function delete(string $key): bool
    {
        return (bool) $this->redis->del($this->prefix . hash('sha256', $key));
    }

    public function stats(): array
    {
        return ['driver' => 'redis', 'ok' => (bool) $this->redis->ping()];
    }
}

function create_cache(array $config): CacheStore
{
    if (($config['cache']['redis_url'] ?? '') !== '' && class_exists('Redis')) {
        try {
            return new RedisCache($config['cache']['redis_url']);
        } catch (Throwable) {
            return new FileCache();
        }
    }
    return new FileCache();
}

final class ApiClient
{
    public function getJson(string $url, array $headers = []): array
    {
        $context = stream_context_create(['http' => ['timeout' => 8, 'header' => implode("\r\n", $headers), 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return ['error' => 'upstream_unavailable', 'url' => $url];
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : ['error' => 'invalid_json', 'url' => $url];
    }

    public function postJson(string $url, array $payload, array $headers = []): array
    {
        $headers[] = 'Content-Type: application/json';
        $context = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 8, 'header' => implode("\r\n", $headers), 'content' => json_encode($payload), 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return ['error' => 'upstream_unavailable', 'url' => $url];
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : ['error' => 'invalid_json', 'url' => $url];
    }
}

final class TmdbService
{
    public function __construct(private array $config, private CacheStore $cache, private ApiClient $client) {}

    public function trending(string $mediaType = 'movie', string $language = 'en-US'): array
    {
        return $this->cached("tmdb:trending:$mediaType:$language", "/trending/$mediaType/day?language=" . rawurlencode($language));
    }

    public function search(string $query, string $language = 'en-US'): array
    {
        if (strlen(trim($query)) < 2) {
            return ['results' => []];
        }
        return $this->cached('tmdb:search:' . md5($query . $language), '/search/multi?include_adult=false&language=' . rawurlencode($language) . '&query=' . rawurlencode($query));
    }

    public function details(string $mediaType, int $id, string $language = 'en-US'): array
    {
        $mediaType = $mediaType === 'tv' ? 'tv' : 'movie';
        $append = 'videos,credits,recommendations,similar,external_ids,content_ratings,release_dates,images';
        return $this->cached("tmdb:details:$mediaType:$id:$language", "/$mediaType/$id?append_to_response=$append&language=" . rawurlencode($language));
    }

    public function seasons(int $showId, int $seasonNumber, string $language = 'en-US'): array
    {
        return $this->cached("tmdb:season:$showId:$seasonNumber:$language", "/tv/$showId/season/$seasonNumber?language=" . rawurlencode($language));
    }

    public function genres(string $mediaType = 'movie', string $language = 'en-US'): array
    {
        $mediaType = $mediaType === 'tv' ? 'tv' : 'movie';
        return $this->cached("tmdb:genres:$mediaType:$language", "/genre/$mediaType/list?language=" . rawurlencode($language));
    }

    public function discover(string $mediaType = 'movie', array $params = []): array
    {
        $mediaType = $mediaType === 'tv' ? 'tv' : 'movie';
        $params = array_filter($params, fn ($value): bool => $value !== null && $value !== '');
        $query = http_build_query($params);
        return $this->cached("tmdb:discover:$mediaType:" . md5($query), "/discover/$mediaType?$query");
    }

    public function externalIds(string $mediaType, int $id): array
    {
        $mediaType = $mediaType === 'tv' ? 'tv' : 'movie';
        return $this->cached("tmdb:external:$mediaType:$id", "/$mediaType/$id/external_ids");
    }

    private function cached(string $key, string $path): array
    {
        return $this->cache->remember($key, (int) $this->config['cache_ttl'], function () use ($path) {
            if ($this->config['api_key'] === '') {
                return ['results' => [], 'notice' => 'Set TMDB_API_KEY to enable live metadata.'];
            }
            $separator = str_contains($path, '?') ? '&' : '?';
            return $this->client->getJson($this->config['base_url'] . $path . $separator . 'api_key=' . rawurlencode($this->config['api_key']));
        });
    }
}

final class TraktService
{
    public function __construct(private array $config, private CacheStore $cache, private ApiClient $client) {}

    public function trending(string $type = 'movies'): array
    {
        return $this->cache->remember("trakt:trending:$type", (int) $this->config['cache_ttl'], function () use ($type) {
            if ($this->config['client_id'] === '') {
                return ['notice' => 'Set TRAKT_CLIENT_ID to enable Trakt trending and OAuth.'];
            }
            return $this->client->getJson($this->config['base_url'] . "/$type/trending", $this->headers());
        });
    }

    public function exchangeCode(string $code): array
    {
        if ($this->config['client_id'] === '' || $this->config['client_secret'] === '') {
            return ['error' => 'trakt_not_configured'];
        }
        return $this->client->postJson($this->config['base_url'] . '/oauth/token', [
            'code' => $code,
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'redirect_uri' => $this->config['redirect_uri'],
            'grant_type' => 'authorization_code',
        ]);
    }

    public function scrobble(string $event, array $payload, string $accessToken): array
    {
        if (!in_array($event, ['start', 'pause', 'stop'], true)) {
            return ['error' => 'invalid_scrobble_event'];
        }
        return $this->client->postJson($this->config['base_url'] . "/scrobble/$event", $payload, array_merge($this->headers(), ['Authorization: Bearer ' . $accessToken]));
    }

    private function headers(): array
    {
        return ['Content-Type: application/json', 'trakt-api-version: 2', 'trakt-api-key: ' . $this->config['client_id']];
    }
}

final class StreamMatcher
{
    public function normalizeId(string $id, string $type = 'movie', ?int $season = null, ?int $episode = null): string
    {
        $id = trim($id);
        if (preg_match('/^(tt\d+|tmdb:\d+|trakt:\d+)$/', $id) && $season !== null && $episode !== null && in_array($type, ['tv', 'series', 'episode'], true)) {
            return $id . ':' . $season . ':' . $episode;
        }
        return $id;
    }

    public function match(array $title, array $streams): array
    {
        $normalizedTitle = $this->normalize($title['title'] ?? '');
        usort($streams, function (array $left, array $right) use ($title, $normalizedTitle): int {
            return $this->score($right, $title, $normalizedTitle) <=> $this->score($left, $title, $normalizedTitle);
        });
        return $streams;
    }

    public function validateUrl(string $url, array $allowedHosts): bool
    {
        $parts = parse_url($url);
        if (!in_array($parts['scheme'] ?? '', ['https'], true)) {
            return false;
        }
        if (!$allowedHosts) {
            return true;
        }
        return in_array($parts['host'] ?? '', $allowedHosts, true);
    }

    private function score(array $stream, array $title, string $normalizedTitle): int
    {
        $score = 0;
        $score += ($stream['imdb_id'] ?? null) === ($title['imdb_id'] ?? null) ? 100 : 0;
        $score += ($stream['tmdb_id'] ?? null) === ($title['tmdb_id'] ?? null) ? 90 : 0;
        $score += ($stream['season'] ?? null) === ($title['season'] ?? null) && ($stream['episode'] ?? null) === ($title['episode'] ?? null) ? 80 : 0;
        similar_text($normalizedTitle, $this->normalize($stream['title'] ?? ''), $percent);
        return $score + (int) round($percent / 2);
    }

    private function normalize(string $title): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $title) ?: $title)) ?? '');
    }
}

final class ProviderRepository
{
    public function __construct(private string $path = __DIR__ . '/../data/providers/stremio-addons.json')
    {
        $directory = dirname($this->path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        if (!is_file($this->path)) {
            file_put_contents($this->path, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    public function all(): array
    {
        $providers = json_decode((string) file_get_contents($this->path), true) ?: [];
        usort($providers, fn (array $left, array $right): int => ($left['priority'] ?? 100) <=> ($right['priority'] ?? 100));
        return $providers;
    }

    public function enabled(): array
    {
        return array_values(array_filter($this->all(), fn (array $provider): bool => (bool) ($provider['enabled'] ?? true)));
    }

    public function save(array $providers): void
    {
        usort($providers, fn (array $left, array $right): int => ($left['priority'] ?? 100) <=> ($right['priority'] ?? 100));
        file_put_contents($this->path, json_encode(array_values($providers), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    public function upsert(array $provider): array
    {
        $providers = $this->all();
        $provider['id'] = $provider['id'] ?? hash('sha256', $provider['manifest_url']);
        $provider['enabled'] = $provider['enabled'] ?? true;
        $provider['priority'] = (int) ($provider['priority'] ?? (count($providers) + 1) * 10);
        $provider['updated_at'] = gmdate('c');
        $found = false;
        foreach ($providers as $index => $existing) {
            if (($existing['id'] ?? '') === $provider['id'] || ($existing['manifest_url'] ?? '') === $provider['manifest_url']) {
                $providers[$index] = array_merge($existing, $provider, ['id' => $existing['id'] ?? $provider['id']]);
                $found = true;
                break;
            }
        }
        if (!$found) {
            $providers[] = $provider;
        }
        $this->save($providers);
        return $provider;
    }

    public function remove(string $id): bool
    {
        $providers = $this->all();
        $next = array_values(array_filter($providers, fn (array $provider): bool => ($provider['id'] ?? '') !== $id));
        $this->save($next);
        return count($next) !== count($providers);
    }

    public function setEnabled(string $id, bool $enabled): bool
    {
        return $this->mutate($id, fn (array $provider): array => array_merge($provider, ['enabled' => $enabled, 'updated_at' => gmdate('c')]));
    }

    public function setPriority(string $id, int $priority): bool
    {
        return $this->mutate($id, fn (array $provider): array => array_merge($provider, ['priority' => $priority, 'updated_at' => gmdate('c')]));
    }

    private function mutate(string $id, callable $callback): bool
    {
        $providers = $this->all();
        $changed = false;
        foreach ($providers as &$provider) {
            if (($provider['id'] ?? '') === $id) {
                $provider = $callback($provider);
                $changed = true;
            }
        }
        $this->save($providers);
        return $changed;
    }
}

final class StremioAddonClient
{
    public function __construct(private ApiClient $client) {}

    public function manifest(string $manifestUrl): array
    {
        $url = $this->normalizeManifestUrl($manifestUrl);
        if (!$this->isSafeRemoteUrl($url)) {
            return ['error' => 'unsafe_manifest_url'];
        }
        $manifest = $this->client->getJson($url);
        return $this->summarizeManifest($manifest, $url);
    }

    public function streams(array $provider, string $type, string $id): array
    {
        $manifestUrl = $provider['manifest_url'] ?? '';
        if ($manifestUrl === '') {
            return [];
        }
        $base = preg_replace('~/manifest\.json(?:\?.*)?$~', '', $this->normalizeManifestUrl($manifestUrl)) ?: rtrim($manifestUrl, '/');
        $url = rtrim($base, '/') . '/stream/' . rawurlencode($type) . '/' . rawurlencode($id) . '.json';
        $payload = $this->client->getJson($url);
        return array_map(fn (array $stream): array => $this->normalizeStream($stream, $provider), $payload['streams'] ?? []);
    }

    public function health(array $provider): array
    {
        $started = microtime(true);
        $manifest = $this->manifest($provider['manifest_url'] ?? '');
        return ['ok' => empty($manifest['error']), 'latency_ms' => (int) round((microtime(true) - $started) * 1000), 'manifest' => $manifest, 'checked_at' => gmdate('c')];
    }

    public function normalizeManifestUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        return str_ends_with(parse_url($url, PHP_URL_PATH) ?: '', 'manifest.json') ? $url : rtrim($url, '/') . '/manifest.json';
    }

    private function isSafeRemoteUrl(string $url): bool
    {
        $parts = parse_url($url);
        return in_array($parts['scheme'] ?? '', ['https'], true) && !in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', '0.0.0.0'], true);
    }

    private function summarizeManifest(array $manifest, string $url): array
    {
        if (isset($manifest['error'])) {
            return $manifest;
        }
        $resources = array_map(fn ($resource): string => is_array($resource) ? (string) ($resource['name'] ?? '') : (string) $resource, $manifest['resources'] ?? []);
        $types = array_values(array_unique(array_merge($manifest['types'] ?? [], array_column($manifest['catalogs'] ?? [], 'type'))));
        return ['id' => $manifest['id'] ?? hash('sha256', $url), 'name' => $manifest['name'] ?? parse_url($url, PHP_URL_HOST), 'version' => $manifest['version'] ?? null, 'description' => $manifest['description'] ?? '', 'manifest_url' => $url, 'resources' => array_values(array_filter($resources)), 'types' => $types, 'catalogs' => $manifest['catalogs'] ?? [], 'id_prefixes' => $manifest['idPrefixes'] ?? [], 'behavior_hints' => $manifest['behaviorHints'] ?? []];
    }

    private function normalizeStream(array $stream, array $provider): array
    {
        $title = trim(($stream['title'] ?? '') . "\n" . ($stream['name'] ?? ''));
        $url = $stream['url'] ?? null;
        $infoHash = $stream['infoHash'] ?? null;
        $fileIdx = $stream['fileIdx'] ?? null;
        return ['id' => hash('sha256', implode('|', [$provider['id'] ?? '', $url, $infoHash, $fileIdx, $title])), 'provider_id' => $provider['id'] ?? null, 'provider' => $provider['name'] ?? 'Stremio addon', 'priority' => (int) ($provider['priority'] ?? 100), 'name' => $stream['name'] ?? ($provider['name'] ?? 'Stream'), 'title' => $title, 'url' => $url, 'external_url' => $stream['externalUrl'] ?? null, 'magnet_url' => $infoHash ? 'magnet:?xt=urn:btih:' . $infoHash : null, 'info_hash' => $infoHash, 'file_idx' => $fileIdx, 'sources' => $stream['sources'] ?? [], 'quality' => $this->extractQuality($title), 'size' => $this->extractSize($title), 'seeds' => $this->extractSeeds($title, $stream), 'source' => $this->extractSource($title, $stream), 'codec' => $this->extractCodec($title), 'rank' => $this->qualityRank($title), 'type' => $infoHash ? 'torrent' : (($stream['externalUrl'] ?? null) ? 'external' : 'http'), 'debrid' => (bool) preg_match('/\b(debrid|real-debrid|alldebrid|premiumize|cached)\b/i', $title), 'behavior_hints' => $stream['behaviorHints'] ?? []];
    }

    private function extractQuality(string $title): string
    {
        return preg_match('/\b(2160p|4k|1080p|720p|480p|cam|ts)\b/i', $title, $match) ? strtoupper($match[1]) : 'Unknown';
    }

    private function extractSize(string $title): ?string
    {
        return preg_match('/\b(\d+(?:\.\d+)?\s*(?:GB|MB))\b/i', $title, $match) ? strtoupper(str_replace(' ', '', $match[1])) : null;
    }

    private function extractSeeds(string $title, array $stream): ?int
    {
        if (isset($stream['seeds'])) {
            return (int) $stream['seeds'];
        }
        return preg_match('/(?:👤|seed(?:s|ers)?|se)\s*[:\-]?\s*(\d+)/i', $title, $match) ? (int) $match[1] : null;
    }

    private function extractSource(string $title, array $stream): string
    {
        if (isset($stream['source'])) {
            return (string) $stream['source'];
        }
        if (preg_match('/\b(WEB[- ]?DL|WEBRip|BluRay|BRRip|HDRip|HDTV|DVDRip|REMUX)\b/i', $title, $match)) {
            return strtoupper($match[1]);
        }
        return ($stream['infoHash'] ?? null) ? 'Torrent' : 'Direct';
    }

    private function extractCodec(string $title): string
    {
        return preg_match('/\b(HEVC|x265|x264|AV1|H\.264|H\.265)\b/i', $title, $match) ? strtoupper($match[1]) : 'Unknown';
    }

    private function qualityRank(string $title): int
    {
        $quality = $this->extractQuality($title);
        return ['4K' => 500, '2160P' => 500, '1080P' => 400, '720P' => 300, '480P' => 200, 'CAM' => 20, 'TS' => 10][$quality] ?? 100;
    }
}

final class StreamAggregator
{
    public function __construct(private ProviderRepository $providers, private StremioAddonClient $client, private CacheStore $cache, private StreamMatcher $matcher, private array $security = []) {}

    public function addProvider(string $manifestUrl, int $priority = 100, bool $enabled = true): array
    {
        $manifest = $this->client->manifest($manifestUrl);
        if (isset($manifest['error'])) {
            return ['error' => 'manifest_unavailable', 'details' => $manifest];
        }
        $provider = ['id' => hash('sha256', $manifest['manifest_url']), 'name' => $manifest['name'], 'manifest_url' => $manifest['manifest_url'], 'resources' => $manifest['resources'], 'types' => $manifest['types'], 'catalogs' => $manifest['catalogs'], 'enabled' => $enabled, 'priority' => $priority, 'sandboxed' => true, 'health' => ['ok' => true, 'checked_at' => gmdate('c')], 'created_at' => gmdate('c')];
        $this->providers->upsert($provider);
        return $provider;
    }

    public function streams(string $type, string $id, ?int $season = null, ?int $episode = null, array $title = []): array
    {
        $id = $this->matcher->normalizeId($id, $type, $season, $episode);
        if (!in_array($type, ['movie', 'series', 'tv', 'episode'], true) || !$this->isSupportedId($id)) {
            return ['streams' => [], 'notice' => 'Stream aggregation requires IMDb IDs, TMDB IDs, or Trakt IDs.'];
        }
        return $this->cache->remember('streams:' . md5($type . ':' . $id), 900, function () use ($type, $id, $title) {
            $stremioType = in_array($type, ['series', 'tv', 'episode'], true) ? 'series' : 'movie';
            $streams = [];
            foreach ($this->providers->enabled() as $provider) {
                if (!in_array('stream', $provider['resources'] ?? [], true) || !in_array($stremioType, $provider['types'] ?? [], true)) {
                    continue;
                }
                $streams = array_merge($streams, $this->client->streams($provider, $stremioType, $id));
            }
            $streams = $this->sanitizeStreams($this->mergeDuplicates($streams));
            if ($title) {
                $streams = $this->matcher->match($title, $streams);
            }
            return ['streams' => $streams, 'providers' => count($this->providers->enabled()), 'cached_at' => gmdate('c')];
        });
    }

    public function testProvider(string $id): array
    {
        foreach ($this->providers->all() as $provider) {
            if (($provider['id'] ?? '') === $id) {
                $health = $this->client->health($provider);
                $this->providers->upsert(array_merge($provider, ['health' => $health]));
                return $health;
            }
        }
        return ['ok' => false, 'error' => 'provider_not_found'];
    }

    private function sanitizeStreams(array $streams): array
    {
        $allowedHosts = $this->security['allowed_stream_hosts'] ?? [];
        return array_values(array_filter(array_map(function (array $stream) use ($allowedHosts): array {
            if (($stream['url'] ?? null) && !$this->matcher->validateUrl((string) $stream['url'], $allowedHosts)) {
                $stream['blocked_url'] = true;
                $stream['url'] = null;
            }
            return $stream;
        }, $streams), fn (array $stream): bool => (bool) ($stream['url'] ?? $stream['external_url'] ?? $stream['magnet_url'] ?? null)));
    }

    private function mergeDuplicates(array $streams): array
    {
        $merged = [];
        foreach ($streams as $stream) {
            $key = $stream['info_hash'] ?: ($stream['url'] ?: $stream['external_url'] ?: $stream['title']);
            $key = strtolower((string) $key);
            if (!isset($merged[$key]) || ($stream['priority'] < $merged[$key]['priority']) || (($stream['rank'] ?? 0) > ($merged[$key]['rank'] ?? 0))) {
                $merged[$key] = $stream;
                continue;
            }
            $merged[$key]['provider'] .= ', ' . $stream['provider'];
        }
        $streams = array_values($merged);
        usort($streams, fn (array $left, array $right): int => [$left['priority'], -($left['rank'] ?? 0), -($left['seeds'] ?? 0)] <=> [$right['priority'], -($right['rank'] ?? 0), -($right['seeds'] ?? 0)]);
        return $streams;
    }

    private function isSupportedId(string $id): bool
    {
        return (bool) preg_match('/^(tt\d+|tmdb:\d+|trakt:\d+)(:\d+:\d+)?$/', $id);
    }
}
