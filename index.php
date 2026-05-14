<?php
$nonce = bin2hex(random_bytes(16));
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: autoplay=(self "https://www.youtube.com"), fullscreen=(self), picture-in-picture=(self), encrypted-media=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net https://www.youtube.com https://www.youtube-nocookie.com 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https://image.tmdb.org https://images.unsplash.com; frame-src https://www.youtube.com https://www.youtube-nocookie.com; connect-src 'self' https://api.themoviedb.org https://api.trakt.tv; media-src 'self' blob: https:; worker-src 'self';");
$page = $_GET['page'] ?? 'home';
$allowedPages = ['home', 'movie', 'tv', 'episode', 'search', 'genre', 'actor', 'watchlist', 'settings', 'admin'];
if (!in_array($page, $allowedPages, true)) { $page = 'home'; }
$tmdbLanguage = $_GET['lang'] ?? 'en-US';
?>
<!doctype html>
<html lang="en" data-page="<?= htmlspecialchars($page, ENT_QUOTES) ?>">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="theme-color" content="#05060d" />
    <title>AstraStream - Cinematic Streaming Dashboard</title>
    <link rel="manifest" href="manifest.webmanifest" />
    <link rel="preload" href="src/styles.css" as="style" />
    <link rel="stylesheet" href="src/styles.css" />
  </head>
  <body>
    <div class="aurora aurora-one"></div>
    <div class="aurora aurora-two"></div>
    <header class="site-header">
      <a class="brand" href="?page=home" aria-label="AstraStream home"><span class="brand-mark">A</span><span>AstraStream</span></a>
      <nav class="primary-nav" aria-label="Primary navigation">
        <a href="?page=home">Home</a>
        <a href="?page=search">Search</a>
        <a href="?page=watchlist">Watchlist</a>
        <a href="?page=settings">Settings</a>
        <a href="?page=admin">Admin</a>
      </nav>
      <form class="global-search" role="search" autocomplete="off">
        <label class="sr-only" for="global-search-input">Search titles, actors, genres, collections</label>
        <input id="global-search-input" type="search" placeholder="Search movies, shows, actors..." data-search-input />
        <div class="suggestions" data-search-suggestions aria-live="polite"></div>
      </form>
      <button class="trakt-login" type="button" data-trakt-login>Connect Trakt</button>
    </header>

    <main id="app" class="page-shell" data-current-page="<?= htmlspecialchars($page, ENT_QUOTES) ?>" data-language="<?= htmlspecialchars($tmdbLanguage, ENT_QUOTES) ?>">
      <?php if ($page === 'home'): ?>
        <section class="hero-banner" data-hero>
          <div class="hero-video" data-youtube-hero aria-hidden="true"></div>
          <div class="hero-scrim"></div>
          <div class="hero-content">
            <p class="eyebrow">Featured tonight</p>
            <h1 data-hero-title>Midnight Signal</h1>
            <p data-hero-overview>A rogue transmission pulls a crew of strangers into a city-sized mystery rendered in neon, rain, and impossible choices.</p>
            <div class="hero-meta" data-hero-meta><span>98% Match</span><span>4K</span><span>Dolby Atmos</span><span>2026</span></div>
            <div class="hero-actions">
              <a class="button primary" href="?page=movie&id=demo-midnight">Watch now</a>
              <button class="button ghost" type="button" data-add-watchlist="demo-midnight">+ Watchlist</button>
            </div>
          </div>
        </section>
        <section class="home-rails" aria-label="Streaming rows">
          <div data-rails></div>
          <div class="sentinel" data-infinite-sentinel>Loading more cinematic rows...</div>
        </section>
      <?php elseif ($page === 'admin'): ?>
        <section class="dashboard-grid admin-dashboard">
          <div class="section-title"><p class="eyebrow">Admin Control Room</p><h1>Operations dashboard</h1><p>Manage providers, users, cache, jobs, featured titles, and streaming health from one dark glass console.</p></div>
          <div class="metric-card"><span>Providers</span><strong data-metric="providers">0</strong><small>Stremio addons · priority ordered</small></div>
          <div class="metric-card"><span>Cache hit rate</span><strong data-metric="cache">93%</strong><small>Redis + edge stale-while-revalidate</small></div>
          <div class="metric-card"><span>Scheduled jobs</span><strong data-metric="jobs">14</strong><small>TMDB daily sync due 02:00 UTC</small></div>
          <div class="panel wide provider-manager"><h2>Stremio provider manager</h2><p class="panel-note">Add Stremio manifest URLs to parse catalogs, stream resources, and supported types. Providers stay empty until an admin adds an addon.</p><form class="provider-form" data-provider-form><input type="url" name="manifest_url" placeholder="https://addon.example.com/manifest.json" required /><input type="number" name="priority" value="100" min="1" step="1" aria-label="Provider priority" /><button class="button primary" type="submit">Add addon</button></form><div class="admin-list provider-list" data-provider-list></div></div>
          <div class="panel"><h2>API health monitoring</h2><div class="health-grid" data-health-grid></div></div>
          <div class="panel wide"><h2>Featured content management</h2><form class="feature-form"><input placeholder="TMDB or IMDb ID" /><button class="button primary" type="button">Pin to hero</button></form><div class="job-log" data-job-log></div></div>
        </section>
      <?php else: ?>
        <section class="detail-layout" data-detail-page>
          <aside class="detail-poster"><img data-detail-poster alt="" loading="lazy" /></aside>
          <article class="detail-copy">
            <p class="eyebrow" data-detail-type><?= htmlspecialchars(ucfirst($page), ENT_QUOTES) ?></p>
            <h1 data-detail-title>Loading cinematic detail</h1>
            <div class="hero-meta" data-detail-meta></div>
            <p data-detail-overview></p>
            <div class="detail-actions">
              <button class="button primary" type="button" data-open-player>Play secure stream</button>
              <button class="button ghost" type="button" data-add-favorite>Favorite</button>
              <button class="button ghost" type="button" data-rate-title>Rate</button>
            </div>
            <section class="panel"><h2>Cast, seasons, recommendations, and similar titles</h2><div class="mini-grid" data-detail-grid></div></section><section class="panel stream-panel"><div class="stream-heading"><div><p class="eyebrow">Stream System</p><h2>Aggregated Stremio streams</h2></div><button class="button ghost" type="button" data-refresh-streams>Refresh streams</button></div><p class="panel-note">Streams are queried from enabled Stremio addons using IMDb, TMDB, or Trakt IDs only.</p><div class="stream-list" data-stream-list></div></section>
          </article>
        </section>
      <?php endif; ?>
    </main>

    <aside class="player-dock" data-player aria-hidden="true">
      <div class="player-topline"><strong data-player-title>Adaptive Player</strong><button type="button" data-close-player>×</button></div>
      <div class="player-frame">
        <video data-video controls playsinline preload="metadata" poster="" crossorigin="anonymous"></video>
        <aside class="episode-sidebar" data-episode-sidebar></aside>
      </div>
      <div class="player-controls">
        <label>Quality<select data-quality><option>Auto</option><option>2160p</option><option>1080p</option><option>720p</option></select></label>
        <label>Subtitles<select data-subtitles><option>Off</option><option>English</option><option>Español</option><option>Deutsch</option></select></label>
        <label>Audio<select data-audio><option>Original</option><option>Commentary</option><option>Descriptive</option></select></label>
        <label>Speed<select data-speed><option>0.75x</option><option selected>1x</option><option>1.25x</option><option>1.5x</option><option>2x</option></select></label>
        <button type="button" data-pip>PiP</button><button type="button" data-cast>Cast</button><button type="button" data-skip-intro>Skip intro</button><button type="button" data-auto-next>Auto-next on</button>
      </div>
    </aside>

    <template id="rail-template"><section class="rail"><div class="rail-heading"><div><p class="eyebrow"></p><h2></h2></div><a href="?page=search">Explore all</a></div><div class="poster-row" tabindex="0"></div></section></template>
    <template id="poster-template"><article class="poster-card"><a href=""><img loading="lazy" alt="" /><div class="poster-gradient"></div><div class="poster-copy"><strong></strong><small></small></div></a></article></template>
    <script nonce="<?= $nonce ?>">window.ASTRA_BOOT={page:<?= json_encode($page) ?>,language:<?= json_encode($tmdbLanguage) ?>};</script>
    <script src="https://cdn.jsdelivr.net/npm/hls.js@1.5.20/dist/hls.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/dashjs@4.7.4/dist/dash.all.min.js" defer></script>
    <script type="module" src="src/main.js"></script>
  </body>
</html>
