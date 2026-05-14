# AstraStream

AstraStream is a PHP, HTML, CSS, and vanilla JavaScript rebuild of the original streaming prototype. It provides an original dark cinematic design language inspired by modern streaming dashboards without copying GoMovies branding or assets.

## Implemented surface

- Home page with a featured hero banner, muted YouTube trailer embed, and Netflix-style horizontal rails for Trending Movies, Trending TV Shows, Popular on Trakt, Top Rated, Recently Released, Continue Watching, Latest Added, genre rows, and Anime.
- Detail-page shell for movies, TV shows, episodes, search, genre, actor, watchlist, and settings routes through `index.php?page=...`.
- Admin dashboard with provider management, user/cache/job health concepts, analytics metrics, and featured content controls.
- Built-in adaptive player UI with HLS.js, DASH.js-ready includes, subtitles, audio tracks, quality selector, speed selector, Chromecast/PiP actions, resume progress, skip intro, auto-next, and episode sidebar.
- Instant debounced search suggestions with poster thumbnails.
- PWA manifest and service worker for shell/offline caching.
- PHP service layer for cached TMDB and Trakt API access, daily sync jobs, Trakt OAuth start/callback flow, stream URL validation, normalized matching, and fuzzy fallback scoring.
- Reference Prisma schema covering users, profiles, watch history, watchlists, favorites, providers, cached streams, playback progress, ratings, reviews, and notifications.


## Stremio stream aggregation

AstraStream includes a Stremio-compatible stream aggregation layer. Admins can add Stremio addon manifest URLs from the Admin page; the backend parses each manifest's catalogs, resources, and types, stores provider priority/enabled state, tests manifest health, and queries enabled `stream/{type}/{id}.json` endpoints. Stream lookups accept IMDb IDs (`tt...`), TMDB IDs (`tmdb:...`), and Trakt IDs (`trakt:...`) only, then merge duplicate torrent, debrid, direct HTTP, and external-player entries while exposing quality, size, seeds, and source metadata.

Useful provider endpoints:

- `app/api.php?action=providers`
- `app/api.php?action=provider-add` with JSON `manifest_url`, `priority`, and `enabled`
- `app/api.php?action=provider-test&id=...`
- `app/api.php?action=streams&type=movie&id=tt0133093`

The home catalog does not use generated placeholder movies or shows. Rows remain empty until TMDB or Trakt credentials return real metadata.

## Runtime stack

This project intentionally avoids a Node runtime. Use PHP's built-in server for local development:

```bash
php -S 127.0.0.1:8000
```

Open <http://127.0.0.1:8000/index.php?page=home>.

## Optional API configuration

Set these environment variables to enable live API calls:

```bash
export TMDB_API_KEY="..."
export TRAKT_CLIENT_ID="..."
export TRAKT_CLIENT_SECRET="..."
export TRAKT_REDIRECT_URI="http://127.0.0.1:8000/app/trakt-oauth.php?action=callback"
export JWT_SECRET="replace-with-a-long-random-secret"
export ALLOWED_STREAM_HOSTS="test-streams.mux.dev,cdn.example.com"
```

Useful PHP endpoints:

- `app/api.php?action=health`
- `app/api.php?action=tmdb-trending&type=movie&language=en-US`
- `app/api.php?action=trakt-trending&type=movies`

Schedule the daily sync with cron:

```cron
0 2 * * * /usr/bin/php /path/to/Streaming/app/daily-sync.php >> /var/log/astrastream-sync.log 2>&1
```

## Security notes

The PHP entrypoint and service layer include secure headers, a CSP, cached upstream requests, provider sandbox status, HTTPS-only stream validation, and rate-limit/JWT settings in configuration. Production deployments should replace the demo JWT secret, store OAuth tokens encrypted, terminate TLS at the edge, and put Redis or another shared cache behind the `FileCache` interface.

## Validation

```bash
php -l index.php
php -l app/services.php
php app/api.php action=health
```
