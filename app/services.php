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
