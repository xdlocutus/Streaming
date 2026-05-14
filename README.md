# StreamBridge

StreamBridge is a static movie and series streaming website that imports IMDb titles and asks Stremio-compatible addons for available streams when a title is opened.

## Features

- Paste an IMDb URL or `tt` id to import a movie or series.
- Resolves metadata through the public Stremio Cinemeta metadata addon.
- Stores imported titles, addon manifests, and optional CORS proxy settings in browser local storage.
- Adds arbitrary Stremio addon manifest URLs and calls their `stream/{type}/{id}.json` endpoints.
- Supports series episode selection using Cinemeta episode ids.
- Plays direct browser-compatible video URLs in an HTML5 player and exposes torrent/magnet-style sources as copyable links.

## Run locally

```bash
npm start
```

Open <http://localhost:5173> in your browser.

## Validate

```bash
npm test
```

## Notes

Many community Stremio addons do not enable browser CORS. Use the Network settings field to configure a trusted proxy prefix if an addon cannot be reached directly from the browser.
