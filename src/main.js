const CINEMETA_URL = 'https://v3-cinemeta.strem.io';
const STORAGE_KEYS = {
  addons: 'streambridge:addons',
  library: 'streambridge:library',
  proxy: 'streambridge:proxy',
};

const state = {
  addons: loadFromStorage(STORAGE_KEYS.addons, []),
  library: loadFromStorage(STORAGE_KEYS.library, []),
  proxy: localStorage.getItem(STORAGE_KEYS.proxy) || '',
  activeTitle: null,
};

const elements = {
  importForm: document.querySelector('#import-form'),
  imdbInput: document.querySelector('#imdb-input'),
  typeSelect: document.querySelector('#type-select'),
  importStatus: document.querySelector('#import-status'),
  addonForm: document.querySelector('#addon-form'),
  addonUrl: document.querySelector('#addon-url'),
  addonList: document.querySelector('#addon-list'),
  proxyUrl: document.querySelector('#proxy-url'),
  libraryGrid: document.querySelector('#library-grid'),
  clearLibrary: document.querySelector('#clear-library'),
  modal: document.querySelector('#title-modal'),
  modalContent: document.querySelector('#modal-content'),
  emptyTemplate: document.querySelector('#empty-state-template'),
};

elements.importForm.addEventListener('submit', handleImportTitle);
elements.addonForm.addEventListener('submit', handleAddAddon);
elements.clearLibrary.addEventListener('click', clearLibrary);
elements.proxyUrl.addEventListener('change', () => {
  state.proxy = elements.proxyUrl.value.trim();
  localStorage.setItem(STORAGE_KEYS.proxy, state.proxy);
});
document.addEventListener('click', handleDocumentClick);
document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape') {
    closeModal();
  }
});

bootstrap();

function bootstrap() {
  elements.proxyUrl.value = state.proxy;
  renderAddons();
  renderLibrary();
}

async function handleImportTitle(event) {
  event.preventDefault();
  const imdbId = extractImdbId(elements.imdbInput.value);
  const selectedType = elements.typeSelect.value;

  if (!imdbId) {
    setStatus('Paste a valid IMDb title URL or tt id.', 'error');
    return;
  }

  setStatus(`Importing ${imdbId}…`, 'loading');

  try {
    const meta = await resolveMetadata(imdbId, selectedType);
    upsertTitle(meta);
    elements.imdbInput.value = '';
    setStatus(`Imported ${meta.name}.`, 'success');
  } catch (error) {
    setStatus(error.message, 'error');
  }
}

async function handleAddAddon(event) {
  event.preventDefault();
  const manifestUrl = normalizeManifestUrl(elements.addonUrl.value);

  try {
    const manifest = await fetchJson(manifestUrl);
    if (!manifest.id || !manifest.name || !Array.isArray(manifest.resources)) {
      throw new Error('That URL did not return a valid Stremio manifest.');
    }

    const addon = {
      id: manifest.id,
      name: manifest.name,
      version: manifest.version || 'unknown',
      description: manifest.description || 'No description provided.',
      manifestUrl,
      baseUrl: manifestUrl.replace(/\/manifest\.json(?:\?.*)?$/, ''),
      resources: manifest.resources,
      types: manifest.types || [],
    };

    state.addons = [addon, ...state.addons.filter((item) => item.id !== addon.id)];
    saveToStorage(STORAGE_KEYS.addons, state.addons);
    elements.addonUrl.value = '';
    renderAddons();
  } catch (error) {
    alert(error.message);
  }
}

async function resolveMetadata(imdbId, selectedType) {
  const typesToTry = selectedType === 'auto' ? ['movie', 'series'] : [selectedType];
  const errors = [];

  for (const type of typesToTry) {
    try {
      const payload = await fetchJson(`${CINEMETA_URL}/meta/${type}/${imdbId}.json`);
      if (payload?.meta?.id) {
        return normalizeMeta(payload.meta, type);
      }
    } catch (error) {
      errors.push(`${type}: ${error.message}`);
    }
  }

  throw new Error(`Could not import ${imdbId}. ${errors.join(' ')}`);
}

function normalizeMeta(meta, type) {
  const episodeVideos = Array.isArray(meta.videos)
    ? meta.videos
        .filter((video) => Number.isInteger(video.season) && Number.isInteger(video.episode))
        .map((video) => ({
          id: video.id,
          name: video.name || `S${video.season} E${video.episode}`,
          season: video.season,
          episode: video.episode,
          released: video.released || '',
        }))
    : [];

  return {
    id: meta.id,
    imdbId: meta.imdb_id || meta.id,
    type,
    name: meta.name,
    poster: meta.poster || meta.background || '',
    background: meta.background || meta.poster || '',
    description: meta.description || 'No synopsis available.',
    releaseInfo: meta.releaseInfo || meta.year || '',
    genres: meta.genres || [],
    runtime: meta.runtime || '',
    imdbRating: meta.imdbRating || '',
    videos: episodeVideos,
    importedAt: new Date().toISOString(),
  };
}

function upsertTitle(meta) {
  state.library = [meta, ...state.library.filter((title) => title.id !== meta.id)];
  saveToStorage(STORAGE_KEYS.library, state.library);
  renderLibrary();
}

function renderLibrary() {
  elements.libraryGrid.innerHTML = '';

  if (!state.library.length) {
    elements.libraryGrid.append(elements.emptyTemplate.content.cloneNode(true));
    return;
  }

  state.library.forEach((title) => {
    const card = document.createElement('button');
    card.type = 'button';
    card.className = 'title-card';
    card.dataset.titleId = title.id;
    card.innerHTML = `
      <span class="poster-wrap">
        ${title.poster ? `<img src="${escapeAttribute(title.poster)}" alt="${escapeAttribute(title.name)} poster" loading="lazy" />` : '<span class="poster-placeholder">No poster</span>'}
      </span>
      <span class="title-card-body">
        <strong>${escapeHtml(title.name)}</strong>
        <small>${escapeHtml(title.type)} · ${escapeHtml(title.releaseInfo || 'Unknown year')}</small>
      </span>
    `;
    elements.libraryGrid.append(card);
  });
}

function renderAddons() {
  elements.addonList.innerHTML = '';

  if (!state.addons.length) {
    elements.addonList.innerHTML = `
      <div class="empty-addon">
        <p>No addons installed yet.</p>
        <small>Add a Stremio manifest URL to enable stream lookups.</small>
      </div>
    `;
    return;
  }

  state.addons.forEach((addon) => {
    const item = document.createElement('article');
    item.className = 'addon-item';
    item.innerHTML = `
      <div>
        <h4>${escapeHtml(addon.name)}</h4>
        <p>${escapeHtml(addon.description)}</p>
        <small>${escapeHtml(addon.version)} · ${escapeHtml(addon.types.join(', ') || 'all types')}</small>
      </div>
      <button class="icon-button" type="button" data-remove-addon="${escapeAttribute(addon.id)}" aria-label="Remove ${escapeAttribute(addon.name)}">×</button>
    `;
    elements.addonList.append(item);
  });
}

async function openTitle(titleId) {
  const title = state.library.find((item) => item.id === titleId);
  if (!title) return;

  state.activeTitle = title;
  elements.modal.setAttribute('aria-hidden', 'false');
  document.body.classList.add('modal-open');
  renderTitleModal(title, { streams: [], loading: Boolean(state.addons.length), error: '' });

  if (!state.addons.length) {
    renderTitleModal(title, { streams: [], loading: false, error: 'Add at least one Stremio addon before looking up streams.' });
    return;
  }

  await loadStreams(title, getSelectedVideoId(title));
}

async function loadStreams(title, videoId) {
  renderTitleModal(title, { streams: [], loading: true, error: '', selectedVideoId: videoId });
  const targetId = videoId || title.imdbId || title.id;
  const streamResults = await Promise.allSettled(
    state.addons.map(async (addon) => {
      const streams = await fetchAddonStreams(addon, title.type, targetId);
      return streams.map((stream) => normalizeStream(stream, addon));
    }),
  );

  const streams = streamResults.flatMap((result) => (result.status === 'fulfilled' ? result.value : []));
  const failures = streamResults.filter((result) => result.status === 'rejected');
  const error = failures.length && !streams.length
    ? 'No addons could be reached. Check CORS support or configure a proxy.'
    : '';

  renderTitleModal(title, { streams, loading: false, error, selectedVideoId: videoId });
}

async function fetchAddonStreams(addon, type, id) {
  const url = `${addon.baseUrl}/stream/${encodeURIComponent(type)}/${encodeURIComponent(id)}.json`;
  const payload = await fetchJson(url);
  return Array.isArray(payload.streams) ? payload.streams : [];
}

function normalizeStream(stream, addon) {
  const title = stream.title || stream.name || stream.description || 'Untitled stream';
  const url = stream.url || '';
  const externalUrl = stream.externalUrl || '';
  const isTorrent = Boolean(stream.infoHash) || url.startsWith('magnet:');
  const magnet = stream.infoHash
    ? `magnet:?xt=urn:btih:${stream.infoHash}${stream.fileIdx ? `&so=${stream.fileIdx}` : ''}`
    : url;

  return {
    title,
    addonName: addon.name,
    quality: stream.behaviorHints?.videoSize || stream.name || stream.tag || '',
    url,
    externalUrl,
    magnet,
    isTorrent,
  };
}

function renderTitleModal(title, { streams, loading, error, selectedVideoId }) {
  const episodes = groupEpisodes(title.videos);
  elements.modalContent.innerHTML = `
    <header class="modal-hero" style="${title.background ? `background-image: linear-gradient(90deg, rgba(7, 10, 20, .96), rgba(7, 10, 20, .56)), url('${escapeAttribute(title.background)}')` : ''}">
      <div class="modal-poster">
        ${title.poster ? `<img src="${escapeAttribute(title.poster)}" alt="${escapeAttribute(title.name)} poster" />` : ''}
      </div>
      <div>
        <p class="eyebrow">${escapeHtml(title.type)} · ${escapeHtml(title.imdbId || title.id)}</p>
        <h2 id="modal-title">${escapeHtml(title.name)}</h2>
        <p class="meta-line">${escapeHtml([title.releaseInfo, title.runtime, title.imdbRating ? `IMDb ${title.imdbRating}` : ''].filter(Boolean).join(' · '))}</p>
        <p>${escapeHtml(title.description)}</p>
        <div class="genre-row">${title.genres.map((genre) => `<span>${escapeHtml(genre)}</span>`).join('')}</div>
      </div>
    </header>

    ${episodes.length ? renderEpisodePicker(episodes, selectedVideoId) : ''}

    <section class="streams-section">
      <div class="section-heading compact">
        <div>
          <p class="eyebrow">Streams</p>
          <h3>Available sources</h3>
        </div>
        <button class="secondary" type="button" data-refresh-streams>Refresh</button>
      </div>
      ${loading ? '<div class="loader">Querying Stremio addons…</div>' : ''}
      ${error ? `<p class="status error">${escapeHtml(error)}</p>` : ''}
      ${!loading && !error && !streams.length ? '<p class="muted">No streams returned for this title.</p>' : ''}
      <div class="stream-list">
        ${streams.map(renderStream).join('')}
      </div>
    </section>
  `;
}

function renderEpisodePicker(seasons, selectedVideoId) {
  return `
    <section class="episode-section">
      <p class="eyebrow">Episodes</p>
      ${seasons.map((season) => `
        <details ${season.season === 1 ? 'open' : ''}>
          <summary>Season ${season.season}</summary>
          <div class="episode-grid">
            ${season.episodes.map((episode) => `
              <button class="episode-pill ${episode.id === selectedVideoId ? 'active' : ''}" type="button" data-video-id="${escapeAttribute(episode.id)}">
                S${episode.season} E${episode.episode}<span>${escapeHtml(episode.name)}</span>
              </button>
            `).join('')}
          </div>
        </details>
      `).join('')}
    </section>
  `;
}

function renderStream(stream) {
  const canPlayInBrowser = stream.url && !stream.isTorrent;

  return `
    <article class="stream-item">
      <div>
        <h4>${escapeHtml(stream.title)}</h4>
        <p>${escapeHtml(stream.addonName)}${stream.quality ? ` · ${escapeHtml(stream.quality)}` : ''}</p>
      </div>
      <div class="stream-actions">
        ${canPlayInBrowser ? `<button type="button" data-play-url="${escapeAttribute(stream.url)}">Play</button>` : ''}
        ${isHttpUrl(stream.externalUrl) ? `<a class="secondary button-link" href="${escapeAttribute(stream.externalUrl)}" target="_blank" rel="noreferrer">Open</a>` : ''}
        ${stream.magnet ? `<button class="secondary" type="button" data-copy-stream="${escapeAttribute(stream.magnet)}">Copy ${stream.isTorrent ? 'magnet' : 'link'}</button>` : ''}
      </div>
    </article>
  `;
}

function groupEpisodes(videos) {
  const seasons = new Map();
  videos.forEach((episode) => {
    if (!seasons.has(episode.season)) {
      seasons.set(episode.season, []);
    }
    seasons.get(episode.season).push(episode);
  });

  return [...seasons.entries()].map(([season, episodes]) => ({
    season,
    episodes: episodes.sort((a, b) => a.episode - b.episode),
  })).sort((a, b) => a.season - b.season);
}

function getSelectedVideoId(title) {
  return title.type === 'series' && title.videos.length ? title.videos[0].id : title.imdbId || title.id;
}

function handleDocumentClick(event) {
  const titleCard = event.target.closest('[data-title-id]');
  if (titleCard) {
    openTitle(titleCard.dataset.titleId);
    return;
  }

  const removeAddonButton = event.target.closest('[data-remove-addon]');
  if (removeAddonButton) {
    state.addons = state.addons.filter((addon) => addon.id !== removeAddonButton.dataset.removeAddon);
    saveToStorage(STORAGE_KEYS.addons, state.addons);
    renderAddons();
    return;
  }

  if (event.target.closest('[data-close-modal]')) {
    closeModal();
    return;
  }

  const videoButton = event.target.closest('[data-video-id]');
  if (videoButton && state.activeTitle) {
    loadStreams(state.activeTitle, videoButton.dataset.videoId);
    return;
  }

  if (event.target.closest('[data-refresh-streams]') && state.activeTitle) {
    loadStreams(state.activeTitle, getSelectedVideoId(state.activeTitle));
    return;
  }

  const playButton = event.target.closest('[data-play-url]');
  if (playButton) {
    playInModal(playButton.dataset.playUrl);
    return;
  }

  const copyButton = event.target.closest('[data-copy-stream]');
  if (copyButton) {
    copyStreamLink(copyButton.dataset.copyStream, copyButton);
  }
}

async function copyStreamLink(value, button) {
  if (navigator.clipboard?.writeText) {
    await navigator.clipboard.writeText(value);
  } else {
    const scratch = document.createElement('textarea');
    scratch.value = value;
    scratch.style.position = 'fixed';
    scratch.style.opacity = '0';
    document.body.append(scratch);
    scratch.select();
    document.execCommand('copy');
    scratch.remove();
  }

  const originalText = button.textContent;
  button.textContent = 'Copied';
  setTimeout(() => {
    button.textContent = originalText;
  }, 1500);
}

function playInModal(url) {
  destroyActivePlayer();

  const player = document.createElement('div');
  player.className = 'player-dock';
  player.innerHTML = `
    <div class="player-header">
      <div>
        <p class="eyebrow">Now playing</p>
        <p class="player-status" role="status">Starting stream…</p>
      </div>
      <button type="button" aria-label="Close player">×</button>
    </div>
    <video controls autoplay playsinline></video>
  `;

  const video = player.querySelector('video');
  const status = player.querySelector('.player-status');
  const closeButton = player.querySelector('button');

  closeButton.addEventListener('click', () => {
    destroyActivePlayer();
  });
  video.addEventListener('playing', () => {
    status.textContent = 'Stream is playing.';
  });
  video.addEventListener('error', () => {
    status.textContent = 'This stream could not be played in the browser. Try copying the link instead.';
  });

  elements.modalContent.prepend(player);
  configurePlayerSource({ player, video, status, url });
  player.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function configurePlayerSource({ player, video, status, url }) {
  if (isHlsUrl(url) && !video.canPlayType('application/vnd.apple.mpegurl')) {
    if (!window.Hls?.isSupported()) {
      status.textContent = 'HLS playback is not supported in this browser.';
      return;
    }

    const hls = new window.Hls();
    player.hls = hls;
    hls.attachMedia(video);
    hls.on(window.Hls.Events.MEDIA_ATTACHED, () => hls.loadSource(url));
    hls.on(window.Hls.Events.MANIFEST_PARSED, () => startPlayback(video, status));
    hls.on(window.Hls.Events.ERROR, (_event, data) => {
      if (data.fatal) {
        status.textContent = 'This HLS stream could not be loaded.';
      }
    });
    return;
  }

  video.src = url;
  startPlayback(video, status);
}

async function startPlayback(video, status) {
  try {
    await video.play();
  } catch (error) {
    status.textContent = 'Click the video play control to start this stream.';
  }
}

function isHlsUrl(url) {
  return url.split('?')[0].toLowerCase().endsWith('.m3u8');
}

function isHttpUrl(url) {
  return /^https?:\/\//i.test(url);
}

function destroyActivePlayer() {
  const player = elements.modalContent.querySelector('.player-dock');
  player?.hls?.destroy();
  player?.remove();
}

function closeModal() {
  destroyActivePlayer();
  elements.modal.setAttribute('aria-hidden', 'true');
  document.body.classList.remove('modal-open');
  state.activeTitle = null;
  elements.modalContent.innerHTML = '';
}

function clearLibrary() {
  if (!state.library.length || !confirm('Clear every imported title from this browser?')) return;
  state.library = [];
  saveToStorage(STORAGE_KEYS.library, state.library);
  renderLibrary();
}

async function fetchJson(url) {
  const requestUrl = state.proxy ? `${state.proxy}${encodeURIComponent(url)}` : url;
  const response = await fetch(requestUrl, { headers: { Accept: 'application/json' } });
  if (!response.ok) {
    throw new Error(`Request failed with ${response.status} for ${url}`);
  }
  return response.json();
}

function extractImdbId(value) {
  return value.trim().match(/tt\d{7,10}/i)?.[0]?.toLowerCase() || '';
}

function normalizeManifestUrl(value) {
  const url = value.trim().replace(/\/$/, '');
  return url.endsWith('/manifest.json') || url.endsWith('manifest.json') ? url : `${url}/manifest.json`;
}

function setStatus(message, type) {
  elements.importStatus.textContent = message;
  elements.importStatus.className = `status ${type}`;
}

function loadFromStorage(key, fallback) {
  try {
    return JSON.parse(localStorage.getItem(key)) || fallback;
  } catch {
    return fallback;
  }
}

function saveToStorage(key, value) {
  localStorage.setItem(key, JSON.stringify(value));
}

function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function escapeAttribute(value) {
  return escapeHtml(value).replaceAll('`', '&#096;');
}
