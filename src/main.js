const STORAGE = {
  profiles: 'astra:profiles',
  watchlist: 'astra:watchlist',
  favorites: 'astra:favorites',
  progress: 'astra:progress',
  cache: 'astra:cache',
  language: 'astra:language',
};

const posterSeeds = [
  ['Midnight Signal', 'movie', '2026', '8.9', 'Sci-Fi Thriller', 'photo-1440404653325-ab127d49abc1'],
  ['Crimson Harbor', 'tv', '2025', '8.6', 'Crime Drama', 'photo-1500530855697-b586d89ba3ee'],
  ['Last Train to Europa', 'movie', '2026', '9.1', 'Adventure', 'photo-1500534314209-a25ddb2bd429'],
  ['Neon Ronin', 'anime', '2024', '8.8', 'Anime Action', 'photo-1518709268805-4e9042af2176'],
  ['The Glass Republic', 'tv', '2026', '8.3', 'Political Drama', 'photo-1485846234645-a62644f84728'],
  ['Afterimage', 'movie', '2025', '8.0', 'Mystery', 'photo-1519608487953-e999c86e7455'],
  ['Northern Lights Club', 'tv', '2026', '7.9', 'Comedy', 'photo-1493246507139-91e8fad9978e'],
  ['Orbit Garden', 'movie', '2024', '8.5', 'Family Fantasy', 'photo-1500534314209-a25ddb2bd429'],
  ['Shogun Starfall', 'anime', '2026', '9.0', 'Anime Epic', 'photo-1495567720989-cebdbdd97913'],
  ['Black Tide Archive', 'tv', '2025', '8.4', 'Documentary', 'photo-1500530855697-b586d89ba3ee'],
];

const railDefinitions = [
  ['Trending Movies', 'TMDB trending/movie + Trakt trending movies'],
  ['Trending TV Shows', 'TMDB trending/tv + Trakt trending shows'],
  ['Popular on Trakt', 'Weighted by plays, watchers, and comments'],
  ['Top Rated', 'TMDB vote average with minimum vote thresholds'],
  ['Recently Released', 'Release dates from TMDB discover endpoints'],
  ['Continue Watching', 'Synced from Trakt history and local resume data'],
  ['Latest Added', 'Provider ingestion queue sorted by cache time'],
  ['Action Genre', 'Genre row with virtualized infinite scrolling'],
  ['Drama Genre', 'Genre row with localized metadata'],
  ['Anime Section', 'Anime keyword + genre collection'],
];

const state = {
  page: window.ASTRA_BOOT?.page || document.querySelector('[data-current-page]')?.dataset.currentPage || 'home',
  language: localStorage.getItem(STORAGE.language) || window.ASTRA_BOOT?.language || 'en-US',
  railsRendered: 0,
  watchlist: readJson(STORAGE.watchlist, []),
  favorites: readJson(STORAGE.favorites, []),
  progress: readJson(STORAGE.progress, {}),
};

const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

function readJson(key, fallback) {
  try { return JSON.parse(localStorage.getItem(key)) ?? fallback; } catch { return fallback; }
}

function writeJson(key, value) {
  localStorage.setItem(key, JSON.stringify(value));
}

function img(seed, width = 780, height = 1170) {
  return `https://images.unsplash.com/${seed}?auto=format&fit=crop&w=${width}&h=${height}&q=80`;
}

function backdrop(seed) {
  return `https://images.unsplash.com/${seed}?auto=format&fit=crop&w=1800&h=900&q=82`;
}

function buildTitle(index, railName = '') {
  const seed = posterSeeds[index % posterSeeds.length];
  const suffix = index >= posterSeeds.length ? ` ${Math.floor(index / posterSeeds.length) + 1}` : '';
  return {
    id: `${seed[1]}-${seed[0].toLowerCase().replace(/[^a-z0-9]+/g, '-')}-${index}`,
    title: `${seed[0]}${suffix}`,
    type: seed[1],
    year: seed[2],
    rating: seed[3],
    genre: railName.includes('Genre') ? railName.replace(' Genre', '') : seed[4],
    poster: img(seed[5]),
    backdrop: backdrop(seed[5]),
    trailer: 'jfKfPfyJRdk',
  };
}

function init() {
  registerServiceWorker();
  bindSearch();
  bindGlobalActions();
  if (state.page === 'home') initHome(); else initDetailPage();
  if (state.page === 'admin') initAdmin();
}

function registerServiceWorker() {
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => undefined);
}

function initHome() {
  hydrateHero(buildTitle(0));
  renderNextRails(6);
  const sentinel = $('[data-infinite-sentinel]');
  if (!sentinel) return;
  const observer = new IntersectionObserver(entries => {
    if (entries.some(entry => entry.isIntersecting)) renderNextRails(2);
  }, { rootMargin: '600px' });
  observer.observe(sentinel);
}

function hydrateHero(title) {
  const hero = $('[data-hero]');
  if (!hero) return;
  hero.style.backgroundImage = `linear-gradient(90deg, rgba(5,6,13,.95), rgba(5,6,13,.52)), url(${title.backdrop})`;
  $('[data-hero-title]').textContent = title.title;
  $('[data-hero-overview]').textContent = 'A premium, original interface concept powered by TMDB metadata, Trakt personalization, secured provider matching, and a modern adaptive player.';
  $('[data-hero-meta]').innerHTML = `<span>${title.rating} TMDB</span><span>${title.genre}</span><span>${title.year}</span><span>${state.language}</span>`;
  const trailer = $('[data-youtube-hero]');
  trailer.innerHTML = `<iframe title="Muted trailer" src="https://www.youtube-nocookie.com/embed/${title.trailer}?autoplay=1&mute=1&controls=0&loop=1&playlist=${title.trailer}&playsinline=1" allow="autoplay; encrypted-media; picture-in-picture" loading="lazy"></iframe>`;
}

function renderNextRails(count) {
  const mount = $('[data-rails]');
  if (!mount) return;
  const railTemplate = $('#rail-template');
  const posterTemplate = $('#poster-template');
  for (let i = 0; i < count && state.railsRendered < railDefinitions.length; i += 1) {
    const [name, source] = railDefinitions[state.railsRendered];
    const rail = railTemplate.content.firstElementChild.cloneNode(true);
    $('.eyebrow', rail).textContent = source;
    $('h2', rail).textContent = name;
    const row = $('.poster-row', rail);
    Array.from({ length: 16 }, (_, cardIndex) => buildTitle(cardIndex + state.railsRendered * 3, name)).forEach(title => {
      const card = posterTemplate.content.firstElementChild.cloneNode(true);
      const link = $('a', card);
      link.href = `?page=${title.type === 'tv' ? 'tv' : 'movie'}&id=${encodeURIComponent(title.id)}`;
      $('img', card).src = title.poster;
      $('img', card).alt = `${title.title} poster`;
      $('strong', card).textContent = title.title;
      $('small', card).textContent = `${title.year} · ${title.rating} · ${title.genre}`;
      row.append(card);
    });
    mount.append(rail);
    state.railsRendered += 1;
  }
}

function bindSearch() {
  const input = $('[data-search-input]');
  const suggestions = $('[data-search-suggestions]');
  if (!input || !suggestions) return;
  let timer;
  input.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
      const query = input.value.trim().toLowerCase();
      if (query.length < 2) { suggestions.innerHTML = ''; return; }
      const results = posterSeeds
        .map((_, index) => buildTitle(index))
        .filter(item => `${item.title} ${item.genre} ${item.type}`.toLowerCase().includes(query))
        .slice(0, 6);
      suggestions.innerHTML = results.map(result => `<a href="?page=search&q=${encodeURIComponent(query)}"><img src="${result.poster}" alt=""><span><strong>${result.title}</strong><small>${result.type} · ${result.genre}</small></span></a>`).join('') || '<p>No local suggestions yet; TMDB multi-search will fill this.</p>';
    }, 180);
  });
}

function bindGlobalActions() {
  document.addEventListener('click', event => {
    const watchButton = event.target.closest('[data-add-watchlist]');
    if (watchButton) toggleList(STORAGE.watchlist, state.watchlist, watchButton.dataset.addWatchlist || 'demo');
    if (event.target.closest('[data-open-player]')) openPlayer();
    if (event.target.closest('[data-close-player]')) closePlayer();
    if (event.target.closest('[data-pip]')) requestPip();
    if (event.target.closest('[data-skip-intro]')) skipIntro();
    if (event.target.closest('[data-trakt-login]')) startTraktOAuth();
    if (event.target.closest('[data-add-favorite]')) toggleList(STORAGE.favorites, state.favorites, location.search || 'detail');
  });
  const speed = $('[data-speed]');
  speed?.addEventListener('change', () => { const video = $('[data-video]'); if (video) video.playbackRate = parseFloat(speed.value); });
}

function toggleList(key, list, id) {
  const next = list.includes(id) ? list.filter(item => item !== id) : [...list, id];
  if (key === STORAGE.watchlist) state.watchlist = next; else state.favorites = next;
  writeJson(key, next);
  toast(list.includes(id) ? 'Removed from list' : 'Saved to your profile');
}

function initDetailPage() {
  const detail = $('[data-detail-page]');
  if (!detail) return;
  const title = buildTitle(new URLSearchParams(location.search).get('id')?.length || 2);
  document.body.style.backgroundImage = `linear-gradient(rgba(5,6,13,.75), rgba(5,6,13,.98)), url(${title.backdrop})`;
  $('[data-detail-title]').textContent = title.title;
  $('[data-detail-overview]').textContent = 'This page models TMDB cast, ratings, genres, trailers, recommendations, similar titles, episode and season metadata while Trakt handles watch history, watchlists, progress sync, and personalized recommendations.';
  $('[data-detail-poster]').src = title.poster;
  $('[data-detail-poster]').alt = `${title.title} poster`;
  $('[data-detail-meta]').innerHTML = `<span>${title.year}</span><span>${title.rating} rating</span><span>${title.genre}</span><span>IMDb/TMDB matched</span>`;
  $('[data-detail-grid]').innerHTML = ['Cast', 'Season metadata', 'Episode guide', 'Similar titles', 'Recommendations', 'Reviews'].map(label => `<article><strong>${label}</strong><small>Loaded from cached TMDB/Trakt API responses.</small></article>`).join('');
}

function openPlayer() {
  const player = $('[data-player]');
  const video = $('[data-video]');
  player?.setAttribute('aria-hidden', 'false');
  $('[data-episode-sidebar]').innerHTML = Array.from({ length: 8 }, (_, i) => `<button type="button">S1:E${i + 1}<small>${i === 0 ? 'Resume 21:04' : 'Queued'}</small></button>`).join('');
  if (!video) return;
  video.poster = img('photo-1485846234645-a62644f84728', 1280, 720);
  if (window.Hls?.isSupported()) {
    const hls = new window.Hls({ enableWorker: true, lowLatencyMode: true });
    hls.loadSource('https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8');
    hls.attachMedia(video);
  } else {
    video.src = 'https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8';
  }
  video.addEventListener('timeupdate', () => {
    state.progress.demo = { seconds: Math.floor(video.currentTime), duration: Math.floor(video.duration || 0), at: Date.now() };
    writeJson(STORAGE.progress, state.progress);
  });
}

function closePlayer() { $('[data-player]')?.setAttribute('aria-hidden', 'true'); }
function requestPip() { const video = $('[data-video]'); if (document.pictureInPictureEnabled && video) video.requestPictureInPicture().catch(() => toast('Picture-in-picture unavailable')); }
function skipIntro() { const video = $('[data-video]'); if (video) video.currentTime = Math.max(video.currentTime, 85); }
function startTraktOAuth() { location.href = 'app/trakt-oauth.php?action=start'; }

function initAdmin() {
  $('[data-provider-list]') && ($('[data-provider-list]').innerHTML = ['HLS CDN', 'DASH Packager', 'Subtitle Index', 'Metadata Proxy'].map((name, i) => `<article><strong>${name}</strong><span>${i === 2 ? 'Degraded' : 'Healthy'}</span><button>Sandbox</button></article>`).join(''));
  $('[data-health-grid]') && ($('[data-health-grid]').innerHTML = ['TMDB', 'Trakt', 'Redis', 'Workers', 'Provider cache', 'JWT auth'].map(item => `<div><strong>${item}</strong><span class="pulse">Operational</span></div>`).join(''));
  $('[data-job-log]') && ($('[data-job-log]').innerHTML = ['Daily TMDB sync', 'Trakt trending import', 'Stream cache pruning', 'Analytics rollup'].map(job => `<p><span>✓</span>${job} completed in background worker.</p>`).join(''));
}

function toast(message) {
  const node = document.createElement('div');
  node.className = 'toast';
  node.textContent = message;
  document.body.append(node);
  setTimeout(() => node.remove(), 2200);
}

init();
