const STORAGE = {
  watchlist: 'astra:watchlist',
  favorites: 'astra:favorites',
  progress: 'astra:progress',
  language: 'astra:language',
};

const railDefinitions = [
  ['Trending Movies', 'tmdb-trending', { type: 'movie' }],
  ['Trending TV Shows', 'tmdb-trending', { type: 'tv' }],
  ['Top Rated Movies', 'tmdb-discover', { type: 'movie', sort_by: 'vote_average.desc' }],
  ['Recently Released', 'tmdb-discover', { type: 'movie', sort_by: 'primary_release_date.desc' }],
  ['Popular Series', 'tmdb-discover', { type: 'tv', sort_by: 'popularity.desc' }],
  ['Popular on Trakt Movies', 'trakt-trending', { type: 'movies' }],
  ['Popular on Trakt Shows', 'trakt-trending', { type: 'shows' }],
];

const state = {
  page: window.ASTRA_BOOT?.page || document.querySelector('[data-current-page]')?.dataset.currentPage || 'home',
  language: localStorage.getItem(STORAGE.language) || window.ASTRA_BOOT?.language || 'en-US',
  railsRendered: 0,
  watchlist: readJson(STORAGE.watchlist, []),
  favorites: readJson(STORAGE.favorites, []),
  progress: readJson(STORAGE.progress, {}),
  currentTitle: null,
  currentStreams: [],
};

const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

function readJson(key, fallback) {
  try { return JSON.parse(localStorage.getItem(key)) ?? fallback; } catch { return fallback; }
}

function writeJson(key, value) {
  localStorage.setItem(key, JSON.stringify(value));
}

async function api(action, params = {}, options = {}) {
  const method = options.method || 'GET';
  if (method === 'GET') {
    const query = new URLSearchParams({ action, ...params });
    return fetch(`app/api.php?${query}`).then(response => response.json());
  }
  return fetch('app/api.php', {
    method,
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action, ...params }),
  }).then(response => response.json());
}

function imageUrl(path, size = 'w500') {
  return path ? `https://image.tmdb.org/t/p/${size}${path}` : '';
}

function mapTmdbTitle(item, fallbackType = 'movie') {
  const mediaType = item.media_type || fallbackType;
  return {
    id: item.id ? `tmdb:${item.id}` : '',
    tmdbId: item.id,
    title: item.title || item.name || 'Untitled',
    type: mediaType === 'tv' ? 'tv' : 'movie',
    stremioType: mediaType === 'tv' ? 'series' : 'movie',
    year: (item.release_date || item.first_air_date || '').slice(0, 4) || 'Unreleased',
    rating: item.vote_average ? item.vote_average.toFixed(1) : 'NR',
    genre: 'TMDB',
    poster: imageUrl(item.poster_path),
    backdrop: imageUrl(item.backdrop_path, 'original'),
  };
}

function mapTraktTitle(item, fallbackType = 'movie') {
  const record = item.movie || item.show || item;
  const ids = record.ids || {};
  const id = ids.imdb ? ids.imdb : (ids.tmdb ? `tmdb:${ids.tmdb}` : (ids.trakt ? `trakt:${ids.trakt}` : ''));
  return {
    id,
    tmdbId: ids.tmdb,
    title: record.title || 'Untitled',
    type: item.show ? 'tv' : fallbackType,
    stremioType: item.show ? 'series' : 'movie',
    year: record.year || 'Unknown',
    rating: item.watchers ? `${item.watchers} watchers` : 'Trakt',
    genre: 'Trakt',
    poster: '',
    backdrop: '',
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

async function initHome() {
  await renderNextRails(2);
  const sentinel = $('[data-infinite-sentinel]');
  if (!sentinel) return;
  const observer = new IntersectionObserver(entries => {
    if (entries.some(entry => entry.isIntersecting)) renderNextRails(1);
  }, { rootMargin: '600px' });
  observer.observe(sentinel);
}

function hydrateHero(title) {
  const hero = $('[data-hero]');
  if (!hero || !title) return;
  if (title.backdrop) hero.style.backgroundImage = `linear-gradient(90deg, rgba(5,6,13,.95), rgba(5,6,13,.52)), url(${title.backdrop})`;
  $('[data-hero-title]').textContent = title.title;
  $('[data-hero-overview]').textContent = 'Metadata is loaded from TMDB and Trakt only; streams are aggregated from admin-enabled Stremio addons.';
  $('[data-hero-meta]').innerHTML = `<span>${title.rating} TMDB</span><span>${title.year}</span><span>${state.language}</span><span>${title.id}</span>`;
  const action = $('.hero-actions .primary');
  if (action) action.href = `?page=${title.type}&id=${encodeURIComponent(title.id)}&tmdb=${encodeURIComponent(title.tmdbId || '')}&title=${encodeURIComponent(title.title)}`;
  $('[data-youtube-hero]').innerHTML = '';
}

async function renderNextRails(count) {
  const mount = $('[data-rails]');
  if (!mount) return;
  const railTemplate = $('#rail-template');
  const posterTemplate = $('#poster-template');
  for (let i = 0; i < count && state.railsRendered < railDefinitions.length; i += 1) {
    const [name, action, params] = railDefinitions[state.railsRendered];
    const data = await api(action, { ...params, language: state.language });
    const titles = normalizeRailResults(data, action, params.type);
    const rail = railTemplate.content.firstElementChild.cloneNode(true);
    $('.eyebrow', rail).textContent = titles.length ? `${params.type.toUpperCase()} · live metadata` : 'No placeholder data';
    $('h2', rail).textContent = name;
    const row = $('.poster-row', rail);
    if (!titles.length) {
      row.innerHTML = '<p class="empty-state">Connect TMDB or Trakt credentials to populate this row with real metadata.</p>';
    } else {
      if (!state.currentTitle) hydrateHero(titles[0]);
      titles.forEach(title => {
        const card = posterTemplate.content.firstElementChild.cloneNode(true);
        const link = $('a', card);
        link.href = `?page=${title.type}&id=${encodeURIComponent(title.id)}&tmdb=${encodeURIComponent(title.tmdbId || '')}&title=${encodeURIComponent(title.title)}`;
        const img = $('img', card);
        if (title.poster) img.src = title.poster; else img.remove();
        $('strong', card).textContent = title.title;
        $('small', card).textContent = `${title.year} · ${title.rating} · ${title.genre}`;
        row.append(card);
      });
    }
    mount.append(rail);
    state.railsRendered += 1;
  }
}

function normalizeRailResults(data, action, type) {
  if (action === 'tmdb-trending' || action === 'tmdb-discover') return (data.results || []).filter(item => item.id).map(item => mapTmdbTitle(item, type)).filter(item => item.id);
  if (action === 'trakt-trending') return Array.isArray(data) ? data.map(item => mapTraktTitle(item, type === 'shows' ? 'tv' : 'movie')).filter(item => item.id) : [];
  return [];
}

function bindSearch() {
  const input = $('[data-search-input]');
  const suggestions = $('[data-search-suggestions]');
  if (!input || !suggestions) return;
  let timer;
  input.addEventListener('input', () => {
    clearTimeout(timer);
    const query = input.value.trim();
    if (query.length < 2) { suggestions.innerHTML = ''; return; }
    suggestions.innerHTML = '<p>Searching TMDB...</p>';
    timer = setTimeout(async () => {
      const data = await api('tmdb-search', { query, language: state.language });
      const results = (data.results || []).filter(item => ['movie', 'tv', 'person'].includes(item.media_type)).slice(0, 8);
      suggestions.innerHTML = results.length ? results.map(item => {
        const title = item.title || item.name || 'Untitled';
        const type = item.media_type === 'tv' ? 'tv' : item.media_type === 'person' ? 'actor' : 'movie';
        const image = imageUrl(item.poster_path || item.profile_path, 'w185');
        return `<a href="?page=${type}&id=tmdb:${item.id}&tmdb=${item.id}&title=${encodeURIComponent(title)}"><span>${image ? `<img src="${image}" alt="" loading="lazy" />` : ''}</span><strong>${escapeHtml(title)}<small>${escapeHtml(item.media_type)} · ${(item.release_date || item.first_air_date || '').slice(0, 4)}</small></strong></a>`;
      }).join('') : '<p>No TMDB results found.</p>';
    }, 220);
  });
}

function bindGlobalActions() {
  document.addEventListener('click', event => {
    const watchButton = event.target.closest('[data-add-watchlist]');
    if (watchButton) toggleList(STORAGE.watchlist, state.watchlist, watchButton.dataset.addWatchlist || currentContentId());
    if (event.target.closest('[data-open-player]')) openPlayer();
    if (event.target.closest('[data-close-player]')) closePlayer();
    if (event.target.closest('[data-pip]')) requestPip();
    if (event.target.closest('[data-skip-intro]')) skipIntro();
    if (event.target.closest('[data-trakt-login]')) startTraktOAuth();
    const streamChoice = event.target.closest('[data-stream-index]');
    if (streamChoice) loadStreamIntoPlayer(Number(streamChoice.dataset.streamIndex));
    if (event.target.closest('[data-add-favorite]')) toggleList(STORAGE.favorites, state.favorites, currentContentId());
    if (event.target.closest('[data-refresh-streams]')) loadStreams();
    const external = event.target.closest('[data-external-stream]');
    if (external) window.open(external.dataset.externalStream, '_blank', 'noopener');
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

function currentContentId() {
  const params = new URLSearchParams(location.search);
  return params.get('id') || (params.get('tmdb') ? `tmdb:${params.get('tmdb')}` : '');
}

async function initDetailPage() {
  const detail = $('[data-detail-page]');
  if (!detail) return;
  const params = new URLSearchParams(location.search);
  const id = currentContentId();
  const tmdbId = params.get('tmdb') || (id.startsWith('tmdb:') ? id.slice(5) : '');
  const fallbackTitle = params.get('title') || id || 'Select a TMDB, IMDb, or Trakt title';
  state.currentTitle = { id, title: fallbackTitle, tmdbId, stremioType: state.page === 'tv' || state.page === 'episode' ? 'series' : 'movie' };
  $('[data-detail-title]').textContent = fallbackTitle;
  $('[data-detail-overview]').textContent = 'Loading normalized metadata, external IDs, streams, and recommendations...';
  if (tmdbId && ['movie', 'tv'].includes(state.page)) {
    const data = await api('tmdb-details', { type: state.page, id: tmdbId, language: state.language });
    if (!data.error && !data.notice) hydrateDetail(data, state.page);
  }
  $('[data-detail-meta]').innerHTML ||= `<span>${id || 'No stream ID'}</span><span>IMDb/TMDB/Trakt only</span><span>Stremio compatible</span>`;
  loadStreams();
}

function hydrateDetail(data, type) {
  const title = data.title || data.name || state.currentTitle?.title || 'Untitled';
  const imdb = data.external_ids?.imdb_id || state.currentTitle?.id || '';
  state.currentTitle = { ...state.currentTitle, title, imdbId: imdb, id: imdb || state.currentTitle?.id, stremioType: type === 'tv' ? 'series' : 'movie' };
  $('[data-detail-title]').textContent = title;
  $('[data-detail-overview]').textContent = data.overview || 'No overview is available from TMDB.';
  $('[data-detail-poster]').src = imageUrl(data.poster_path);
  $('[data-detail-meta]').innerHTML = `<span>${(data.release_date || data.first_air_date || '').slice(0, 4) || 'Unreleased'}</span><span>${(data.vote_average || 0).toFixed(1)} TMDB</span><span>${imdb || 'No IMDb ID'}</span>`;
  const credits = (data.credits?.cast || []).slice(0, 6).map(person => `<article><strong>${escapeHtml(person.name)}</strong><small>${escapeHtml(person.character || person.known_for_department || 'Cast')}</small></article>`).join('');
  const recs = (data.recommendations?.results || []).slice(0, 4).map(item => `<article><strong>${escapeHtml(item.title || item.name)}</strong><small>Recommendation</small></article>`).join('');
  $('[data-detail-grid]').innerHTML = credits + recs || '<p class="empty-state">No credits or recommendations returned.</p>';
}

async function loadStreams() {
  const list = $('[data-stream-list]');
  if (!list) return;
  const id = state.currentTitle?.imdbId || currentContentId();
  if (!id) {
    list.innerHTML = '<p class="empty-state">No IMDb, TMDB, or Trakt ID is available for this title.</p>';
    return;
  }
  list.innerHTML = '<p class="empty-state">Querying enabled Stremio addons...</p>';
  const data = await api('streams', { type: state.currentTitle?.stremioType || 'movie', id, title: state.currentTitle?.title || '' });
  state.currentStreams = data.streams || [];
  renderStreams();
}

function renderStreams() {
  const list = $('[data-stream-list]');
  if (!list) return;
  if (!state.currentStreams.length) {
    list.innerHTML = '<p class="empty-state">No streams returned by enabled addons. Add providers in the admin panel or verify provider health.</p>';
    return;
  }
  list.innerHTML = state.currentStreams.map(stream => `
    <article class="stream-card">
      <div><strong>${escapeHtml(stream.name || stream.provider)}</strong><small>${escapeHtml(stream.provider)} · ${escapeHtml(stream.source)} · ${stream.debrid ? 'Debrid cached' : escapeHtml(stream.type)}</small></div>
      <span>${escapeHtml(stream.quality)}</span><span>${escapeHtml(stream.size || 'Size n/a')}</span><span>${stream.seeds ?? 'Seeds n/a'}</span>
      ${stream.url ? '<button class="button primary" type="button" data-open-player>Play</button>' : ''}
      ${stream.external_url ? `<button class="button ghost" type="button" data-external-stream="${escapeHtml(stream.external_url)}">External</button>` : ''}
      ${stream.magnet_url ? `<button class="button ghost" type="button" data-external-stream="${escapeHtml(stream.magnet_url)}">Magnet</button>` : ''}
    </article>`).join('');
}

function openPlayer() {
  const player = $('[data-player]');
  const video = $('[data-video]');
  player?.setAttribute('aria-hidden', 'false');
  $('[data-player-title]').textContent = state.currentTitle?.title || 'Adaptive Player';
  $('[data-episode-sidebar]').innerHTML = state.currentStreams.map((stream, index) => `<button type="button" data-stream-index="${index}"><span>${escapeHtml(stream.quality)} · ${escapeHtml(stream.codec || '')}</span><small>${escapeHtml(stream.provider)} · ${stream.seeds ?? 'n/a'} seeds</small></button>`).join('') || '<p class="empty-state">No stream selected.</p>';
  loadStreamIntoPlayer(state.currentStreams.findIndex(item => item.url));
}

function loadStreamIntoPlayer(index) {
  const video = $('[data-video]');
  const stream = state.currentStreams[index] || null;
  if (!video || !stream?.url) return;
  video.poster = '';
  video.src = '';
  video.onloadedmetadata = () => {
    const saved = state.progress[currentContentId()];
    if (saved && saved < video.duration - 20) video.currentTime = saved;
  };
  video.ontimeupdate = () => {
    if (!video.duration) return;
    state.progress[currentContentId()] = Math.floor(video.currentTime);
    writeJson(STORAGE.progress, state.progress);
  };
  video.onerror = () => {
    const next = state.currentStreams.findIndex((item, nextIndex) => nextIndex > index && item.url);
    if (next > -1) { toast('Stream failed; switching provider'); loadStreamIntoPlayer(next); }
  };
  if (stream.url.includes('.m3u8') && window.Hls?.isSupported()) {
    const hls = new window.Hls({ enableWorker: true, lowLatencyMode: true });
    hls.loadSource(stream.url);
    hls.attachMedia(video);
    hls.on(window.Hls.Events.ERROR, (_, data) => { if (data.fatal) hls.recoverMediaError(); });
  } else if (stream.url.includes('.mpd') && window.dashjs) {
    window.dashjs.MediaPlayer().create().initialize(video, stream.url, false);
  } else {
    video.src = stream.url;
  }
}

function closePlayer() { $('[data-player]')?.setAttribute('aria-hidden', 'true'); }
function requestPip() { const video = $('[data-video]'); if (document.pictureInPictureEnabled && video) video.requestPictureInPicture().catch(() => toast('Picture-in-picture unavailable')); }
function skipIntro() { const video = $('[data-video]'); if (video) video.currentTime = Math.max(video.currentTime, 85); }
function startTraktOAuth() { location.href = 'app/trakt-oauth.php?action=start'; }

async function initAdmin() {
  await renderProviders();
  $('[data-provider-form]')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const result = await api('provider-add', { manifest_url: form.get('manifest_url'), priority: form.get('priority') }, { method: 'POST' });
    toast(result.error ? 'Provider manifest could not be loaded' : 'Provider added');
    await renderProviders();
  });
  $('[data-health-grid]') && ($('[data-health-grid]').innerHTML = ['TMDB', 'IMDb IDs', 'Trakt', 'Stremio manifests', 'Stream cache', 'Provider health'].map(item => `<div><strong>${item}</strong><span class="pulse">Configured check</span></div>`).join(''));
  $('[data-job-log]') && ($('[data-job-log]').innerHTML = ['TMDB daily sync', 'Trakt trending import', 'Stremio provider health check', 'Stream duplicate merge'].map(job => `<p><span>✓</span>${job} ready.</p>`).join(''));
}

async function renderProviders() {
  const mount = $('[data-provider-list]');
  if (!mount) return;
  const data = await api('providers');
  const providers = data.providers || [];
  $('[data-metric="providers"]') && ($('[data-metric="providers"]').textContent = providers.length);
  if (!providers.length) {
    mount.innerHTML = '<p class="empty-state">No addons configured. Add a Stremio manifest URL to begin stream aggregation.</p>';
    return;
  }
  mount.innerHTML = providers.map(provider => `
    <article data-provider-id="${escapeHtml(provider.id)}">
      <div><strong>${escapeHtml(provider.name)}</strong><small>${escapeHtml(provider.manifest_url)} · ${(provider.resources || []).join(', ')} · ${(provider.types || []).join(', ')}</small></div>
      <input type="number" value="${provider.priority}" min="1" data-provider-priority aria-label="Priority for ${escapeHtml(provider.name)}" />
      <button type="button" data-provider-toggle>${provider.enabled ? 'Disable' : 'Enable'}</button>
      <button type="button" data-provider-test>Test</button>
      <button type="button" data-provider-remove>Remove</button>
    </article>`).join('');
  mount.onclick = handleProviderClick;
  mount.onchange = handleProviderPriority;
}

async function handleProviderClick(event) {
  const row = event.target.closest('[data-provider-id]');
  if (!row) return;
  const id = row.dataset.providerId;
  if (event.target.closest('[data-provider-toggle]')) await api('provider-enable', { id, enabled: event.target.textContent === 'Enable' }, { method: 'POST' });
  if (event.target.closest('[data-provider-test]')) toast((await api('provider-test', { id }, { method: 'POST' })).ok ? 'Provider healthy' : 'Provider failed health check');
  if (event.target.closest('[data-provider-remove]')) await api('provider-remove', { id }, { method: 'POST' });
  await renderProviders();
}

async function handleProviderPriority(event) {
  const input = event.target.closest('[data-provider-priority]');
  const row = event.target.closest('[data-provider-id]');
  if (!input || !row) return;
  await api('provider-priority', { id: row.dataset.providerId, priority: input.value }, { method: 'POST' });
  toast('Provider priority updated');
}

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>'"]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char]));
}

function toast(message) {
  const node = document.createElement('div');
  node.className = 'toast';
  node.textContent = message;
  document.body.append(node);
  setTimeout(() => node.remove(), 2200);
}

init();
