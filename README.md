# AstraStream

AstraStream is a PHP, HTML, CSS, and vanilla JavaScript streaming aggregator. The current implementation keeps the lightweight PHP runtime while adding production-oriented service boundaries for TMDB metadata, Trakt OAuth, Stremio provider aggregation, stream caching, a cinematic UI, and an adaptive web player.

## Implemented surface

- Home page with a featured hero banner and live metadata rails for TMDB trending, TMDB discovery rows, and Trakt trending rows.
- Detail pages for movies and TV shows that hydrate TMDB overview, poster art, external IDs, cast, and recommendations when API credentials are configured.
- Admin dashboard with Stremio provider management, manifest parsing, provider health checks, provider priority, and cache health metrics.
- Stremio-compatible stream aggregation with manifest parsing, stream endpoint querying, duplicate merging, provider priority, quality/source/codec parsing, HTTPS host validation, and cached stream results.
- Adaptive player with HLS.js, DASH.js, direct-file fallback, resume progress in local storage, PiP, skip intro, provider stream switching, and fatal HLS media recovery.
- Instant debounced TMDB multi-search for movies, TV shows, and people.
- PWA manifest and service worker for shell/offline caching.
- Cache abstraction with Redis support when `REDIS_URL` and the PHP Redis extension are available, plus file-cache fallback.
- Trakt OAuth authorization-code exchange with CSRF state validation and local token persistence for single-server deployments.
- Reference Prisma schema expanded for production data modeling: users, sessions, OAuth accounts, media, movies, TV shows, seasons, episodes, genres, credits, images, trailers, recommendations, providers, cached streams, playback progress, playback sessions, and jobs.

## Architecture

```mermaid
flowchart TD
  UI[index.php + src/main.js] --> API[app/api.php]
  API --> TMDB[TmdbService]
  API --> Trakt[TraktService]
  API --> Streams[StreamAggregator]
  Streams --> Providers[ProviderRepository]
  Streams --> Stremio[StremioAddonClient]
  Streams --> Matcher[StreamMatcher]
  TMDB --> Cache[CacheStore Redis/File]
  Trakt --> Cache
  Streams --> Cache
  Daily[app/daily-sync.php] --> TMDB
  Daily --> Trakt
  Daily --> Stremio
  Prisma[prisma/schema.prisma] -. deployment teams can migrate .-> Postgres[(PostgreSQL)]
```

## API endpoints

Useful PHP endpoints:

- `app/api.php?action=health`
- `app/api.php?action=cache-stats`
- `app/api.php?action=tmdb-trending&type=movie&language=en-US`
- `app/api.php?action=tmdb-search&query=matrix`
- `app/api.php?action=tmdb-details&type=movie&id=603`
- `app/api.php?action=tmdb-season&show_id=1399&season=1`
- `app/api.php?action=tmdb-genres&type=movie`
- `app/api.php?action=tmdb-discover&type=movie&sort_by=vote_average.desc`
- `app/api.php?action=trakt-trending&type=movies`
- `app/api.php?action=providers`
- `app/api.php?action=provider-add` with JSON `manifest_url`, `priority`, and `enabled`
- `app/api.php?action=provider-test&id=...`
- `app/api.php?action=streams&type=movie&id=tt0133093`

## Stremio stream aggregation

Admins add Stremio addon manifest URLs from the Admin page. The backend parses each manifest's catalogs, resources, and types; stores provider priority/enabled state; tests manifest health; and queries enabled `stream/{type}/{id}.json` endpoints. Stream lookups accept IMDb IDs (`tt...`), TMDB IDs (`tmdb:...`), and Trakt IDs (`trakt:...`) with optional season/episode suffixes. Duplicate torrent, debrid, direct HTTP, and external-player entries are merged while exposing quality, codec, size, seeds, source, provider, and validation metadata.

## Runtime stack

Use PHP's built-in server for local development:

```bash
php -S 127.0.0.1:8000
```

Open <http://127.0.0.1:8000/index.php?page=home>.

## Environment variables

```bash
export TMDB_API_KEY="..."
export TRAKT_CLIENT_ID="..."
export TRAKT_CLIENT_SECRET="..."
export TRAKT_REDIRECT_URI="http://127.0.0.1:8000/app/trakt-oauth.php?action=callback"
export JWT_SECRET="replace-with-a-long-random-secret"
export ALLOWED_STREAM_HOSTS="test-streams.mux.dev,cdn.example.com"
export REDIS_URL="redis://127.0.0.1:6379/0"
export DATABASE_URL="postgresql://user:password@localhost:5432/astrastream"
```

`REDIS_URL` is optional. If the PHP Redis extension is unavailable or Redis cannot be reached, AstraStream safely falls back to the local file cache.

## Background sync

Schedule cache warming and provider health checks with cron:

```cron
0 2 * * * /usr/bin/php /path/to/Streaming/app/daily-sync.php >> /var/log/astrastream-sync.log 2>&1
```

## Security notes

The PHP entrypoint sets secure browser headers and a CSP. Provider manifests must use HTTPS and cannot point to localhost addresses. Stream URLs are sanitized against `ALLOWED_STREAM_HOSTS` before playback links are returned. Trakt OAuth uses a state token before exchanging codes. Production deployments should terminate TLS at the edge, encrypt Trakt token storage with a managed secret, place Redis/PostgreSQL on private networks, and put admin routes behind application authentication or edge access control.

## Validation

```bash
npm test
php app/api.php action=health
node --check src/main.js
```
