<?php
return [
    'tmdb' => [
        'base_url' => 'https://api.themoviedb.org/3',
        'image_base_url' => 'https://image.tmdb.org/t/p',
        'api_key' => getenv('TMDB_API_KEY') ?: '',
        'cache_ttl' => 86400,
        'sync_cron' => '0 2 * * *',
    ],
    'trakt' => [
        'base_url' => 'https://api.trakt.tv',
        'client_id' => getenv('TRAKT_CLIENT_ID') ?: '',
        'client_secret' => getenv('TRAKT_CLIENT_SECRET') ?: '',
        'redirect_uri' => getenv('TRAKT_REDIRECT_URI') ?: 'http://localhost:8000/app/trakt-oauth.php?action=callback',
        'cache_ttl' => 900,
    ],
    'security' => [
        'jwt_secret' => getenv('JWT_SECRET') ?: 'change-me-in-production',
        'rate_limit_per_minute' => 90,
        'allowed_stream_hosts' => array_filter(explode(',', getenv('ALLOWED_STREAM_HOSTS') ?: 'test-streams.mux.dev')),
    ],
];
