<?php
declare(strict_types=1);

final class FileCache
{
    public function __construct(private string $directory = __DIR__ . '/../data/cache')
    {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }
    }

    public function remember(string $key, int $ttl, callable $producer): array
    {
        $path = $this->path($key);
        if (is_file($path) && filemtime($path) + $ttl > time()) {
            return json_decode((string) file_get_contents($path), true) ?: [];
        }
        $value = $producer();
        file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $value;
    }

    private function path(string $key): string
    {
        return $this->directory . '/' . hash('sha256', $key) . '.json';
    }
}

final class ApiClient
{
    public function getJson(string $url, array $headers = []): array
    {
        $context = stream_context_create(['http' => ['timeout' => 8, 'header' => implode("\r\n", $headers)]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return ['error' => 'upstream_unavailable', 'url' => $url];
        }
        return json_decode($body, true) ?: [];
    }
}

final class TmdbService
{
    public function __construct(private array $config, private FileCache $cache, private ApiClient $client) {}

    public function trending(string $mediaType = 'movie', string $language = 'en-US'): array
    {
        return $this->cached("tmdb:trending:$mediaType:$language", "/trending/$mediaType/day?language=" . rawurlencode($language));
    }

    public function details(string $mediaType, int $id, string $language = 'en-US'): array
    {
        $append = 'videos,credits,recommendations,similar,external_ids,content_ratings,release_dates';
        return $this->cached("tmdb:details:$mediaType:$id:$language", "/$mediaType/$id?append_to_response=$append&language=" . rawurlencode($language));
    }

    public function seasons(int $showId, int $seasonNumber, string $language = 'en-US'): array
    {
        return $this->cached("tmdb:season:$showId:$seasonNumber:$language", "/tv/$showId/season/$seasonNumber?language=" . rawurlencode($language));
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
    public function __construct(private array $config, private FileCache $cache, private ApiClient $client) {}

    public function trending(string $type = 'movies'): array
    {
        return $this->cache->remember("trakt:trending:$type", (int) $this->config['cache_ttl'], function () use ($type) {
            if ($this->config['client_id'] === '') {
                return ['notice' => 'Set TRAKT_CLIENT_ID to enable Trakt trending and OAuth.'];
            }
            return $this->client->getJson($this->config['base_url'] . "/$type/trending", [
                'Content-Type: application/json',
                'trakt-api-version: 2',
                'trakt-api-key: ' . $this->config['client_id'],
            ]);
        });
    }
}

final class StreamMatcher
{
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
        return in_array($parts['scheme'] ?? '', ['https'], true) && in_array($parts['host'] ?? '', $allowedHosts, true);
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
        file_put_contents($this->path, json_encode(array_values($providers), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
        $providers = $this->all();
        $changed = false;
        foreach ($providers as &$provider) {
            if (($provider['id'] ?? '') === $id) {
                $provider['enabled'] = $enabled;
                $provider['updated_at'] = gmdate('c');
                $changed = true;
            }
        }
        $this->save($providers);
        return $changed;
    }

    public function setPriority(string $id, int $priority): bool
    {
        $providers = $this->all();
        $changed = false;
        foreach ($providers as &$provider) {
            if (($provider['id'] ?? '') === $id) {
                $provider['priority'] = $priority;
                $provider['updated_at'] = gmdate('c');
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
        return [
            'ok' => empty($manifest['error']),
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'manifest' => $manifest,
            'checked_at' => gmdate('c'),
        ];
    }

    public function normalizeManifestUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        return str_ends_with(parse_url($url, PHP_URL_PATH) ?: '', 'manifest.json') ? $url : rtrim($url, '/') . '/manifest.json';
    }

    private function summarizeManifest(array $manifest, string $url): array
    {
        if (isset($manifest['error'])) {
            return $manifest;
        }
        $resources = array_map(fn ($resource): string => is_array($resource) ? (string) ($resource['name'] ?? '') : (string) $resource, $manifest['resources'] ?? []);
        $types = array_values(array_unique(array_merge($manifest['types'] ?? [], array_column($manifest['catalogs'] ?? [], 'type'))));
        return [
            'id' => $manifest['id'] ?? hash('sha256', $url),
            'name' => $manifest['name'] ?? parse_url($url, PHP_URL_HOST),
            'version' => $manifest['version'] ?? null,
            'description' => $manifest['description'] ?? '',
            'manifest_url' => $url,
            'resources' => array_values(array_filter($resources)),
            'types' => $types,
            'catalogs' => $manifest['catalogs'] ?? [],
            'id_prefixes' => $manifest['idPrefixes'] ?? [],
            'behavior_hints' => $manifest['behaviorHints'] ?? [],
        ];
    }

    private function normalizeStream(array $stream, array $provider): array
    {
        $title = trim(($stream['title'] ?? '') . "\n" . ($stream['name'] ?? ''));
        $url = $stream['url'] ?? null;
        $infoHash = $stream['infoHash'] ?? null;
        $fileIdx = $stream['fileIdx'] ?? null;
        return [
            'id' => hash('sha256', implode('|', [$provider['id'] ?? '', $url, $infoHash, $fileIdx, $title])),
            'provider_id' => $provider['id'] ?? null,
            'provider' => $provider['name'] ?? 'Stremio addon',
            'priority' => (int) ($provider['priority'] ?? 100),
            'name' => $stream['name'] ?? ($provider['name'] ?? 'Stream'),
            'title' => $title,
            'url' => $url,
            'external_url' => $stream['externalUrl'] ?? null,
            'magnet_url' => $infoHash ? 'magnet:?xt=urn:btih:' . $infoHash : null,
            'info_hash' => $infoHash,
            'file_idx' => $fileIdx,
            'sources' => $stream['sources'] ?? [],
            'quality' => $this->extractQuality($title),
            'size' => $this->extractSize($title),
            'seeds' => $this->extractSeeds($title, $stream),
            'source' => $this->extractSource($title, $stream),
            'type' => $infoHash ? 'torrent' : (($stream['externalUrl'] ?? null) ? 'external' : 'http'),
            'debrid' => (bool) preg_match('/\b(debrid|real-debrid|alldebrid|premiumize|cached)\b/i', $title),
            'behavior_hints' => $stream['behaviorHints'] ?? [],
        ];
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
}

final class StreamAggregator
{
    public function __construct(private ProviderRepository $providers, private StremioAddonClient $client) {}

    public function addProvider(string $manifestUrl, int $priority = 100, bool $enabled = true): array
    {
        $manifest = $this->client->manifest($manifestUrl);
        if (isset($manifest['error'])) {
            return ['error' => 'manifest_unavailable', 'details' => $manifest];
        }
        $provider = [
            'id' => hash('sha256', $manifest['manifest_url']),
            'name' => $manifest['name'],
            'manifest_url' => $manifest['manifest_url'],
            'resources' => $manifest['resources'],
            'types' => $manifest['types'],
            'catalogs' => $manifest['catalogs'],
            'enabled' => $enabled,
            'priority' => $priority,
            'health' => ['ok' => true, 'checked_at' => gmdate('c')],
            'created_at' => gmdate('c'),
        ];
        $this->providers->upsert($provider);
        return $provider;
    }

    public function streams(string $type, string $id): array
    {
        if (!in_array($type, ['movie', 'series', 'tv', 'episode'], true) || !$this->isSupportedId($id)) {
            return ['streams' => [], 'notice' => 'Stream aggregation requires IMDb IDs, TMDB IDs, or Trakt IDs.'];
        }
        $stremioType = in_array($type, ['series', 'tv', 'episode'], true) ? 'series' : 'movie';
        $streams = [];
        foreach ($this->providers->enabled() as $provider) {
            if (!in_array('stream', $provider['resources'] ?? [], true) || !in_array($stremioType, $provider['types'] ?? [], true)) {
                continue;
            }
            $streams = array_merge($streams, $this->client->streams($provider, $stremioType, $id));
        }
        return ['streams' => $this->mergeDuplicates($streams), 'providers' => count($this->providers->enabled())];
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

    private function mergeDuplicates(array $streams): array
    {
        $merged = [];
        foreach ($streams as $stream) {
            $key = $stream['info_hash'] ?: ($stream['url'] ?: $stream['external_url'] ?: $stream['title']);
            $key = strtolower((string) $key);
            if (!isset($merged[$key]) || ($stream['priority'] < $merged[$key]['priority'])) {
                $merged[$key] = $stream;
                continue;
            }
            $merged[$key]['provider'] .= ', ' . $stream['provider'];
        }
        $streams = array_values($merged);
        usort($streams, fn (array $left, array $right): int => [$left['priority'], -($left['seeds'] ?? 0)] <=> [$right['priority'], -($right['seeds'] ?? 0)]);
        return $streams;
    }

    private function isSupportedId(string $id): bool
    {
        return (bool) preg_match('/^(tt\d+|tmdb:\d+|trakt:\d+)(:\d+:\d+)?$/', $id);
    }
}
