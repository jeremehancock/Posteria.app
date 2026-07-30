<?php
// Marquee poster API v1 — configuration.
//
// This endpoint is self-contained: it shares no code with api/fetch/posters and
// introduces no environment variables. The four credentials below are the only
// values read from the environment; everything else is a constant, so deploying
// this endpoint requires no configuration change.

if (!defined('MARQUEE_API_V1')) {
    http_response_code(404);
    exit;
}

// Endpoint identity
const MARQUEE_ENDPOINT_VERSION = 'v1';
const MARQUEE_USER_AGENT = 'Posteria-Marquee-API/1.0';

// TMDB
const TMDB_BASE_URL = 'https://api.themoviedb.org/3';
const TMDB_IMAGE_BASE_URL = 'https://image.tmdb.org/t/p/';
const TMDB_SIZE_FULL = 'original';
const TMDB_SIZE_THUMB = 'w342';

// fanart.tv
const FANART_BASE_URL = 'https://webservice.fanart.tv/v3';

// TheTVDB v4. Records are located by remote id (IMDb for movies, the TVDB id TMDB
// already gives us for shows), never by a title-derived slug, so artwork is always
// provably the requested work's.
const TVDB_BASE_URL = 'https://api4.thetvdb.com/v4';
const TVDB_ARTWORK_TYPE_MOVIE_POSTER = 14;
const TVDB_ARTWORK_TYPE_SERIES_POSTER = 2;
const TVDB_ARTWORK_TYPE_SEASON_POSTER = 7;
// Issued tokens last about a month; re-login well before that.
const TVDB_TOKEN_TTL_SECONDS = 20 * 24 * 60 * 60;

// Mediux is deliberately not a source here. The legacy endpoint's staging host
// (staged.mediux.io) is gone — Cloudflare 1033, origin unreachable — and the
// production host api.mediux.pro rejects this deployment's MEDIUX_API_KEY with
// 401 INVALID_CREDENTIALS. Rather than ship a source that is advertised and never
// contributes, it is omitted entirely. Restoring it needs only a working
// credential, which is a value change, not a code change.

// Client identification. The header is identification for logging, not a secret:
// the name is a fixed string in open-source client code and the timestamp is just
// the current time, so anyone can construct one. The tolerance is therefore wide
// enough that a self-hosted client with a drifting clock is never locked out.
const CLIENT_HEADER_NAME = 'X-Client-Info';
const CLIENT_TS_TOLERANCE_MS = 24 * 60 * 60 * 1000;

function marqueeAcceptedClientNames(): array
{
    return ['Marquee'];
}

// Request shaping
const LIMIT_DEFAULT = 200;
const LIMIT_MAX = 500;
const VALID_TYPES = ['movie', 'show', 'season', 'collection'];
const VALID_SOURCE_TOKENS = ['tmdb', 'fanart', 'tvdb'];

// Source names as they appear in the providers map and in each poster's `source`.
// Deliberately distinct from the request tokens above.
const SOURCE_LABELS = [
    'tmdb' => 'tmdb',
    'fanart' => 'fanart.tv',
    'tvdb' => 'thetvdb',
];

// Upstream timeouts. One slow provider must not extend the response indefinitely,
// and the built-in PHP server is serial so a stuck request blocks the queue.
const HTTP_TIMEOUT_SECONDS = 8;
const HTTP_CONNECT_TIMEOUT_SECONDS = 4;

// Caching and rate limiting. Filesystem or APCu only — no connection string.
const CACHE_TTL_SECONDS = 24 * 60 * 60;
const RATE_LIMIT_PER_MINUTE = 60;
const RATE_LIMIT_PER_HOUR = 3000;

// Resolution scoring
const RESOLVE_SCORE_FLOOR = 40;

/**
 * The only place in this tree that reads the environment.
 *
 * Returns null rather than false for an unset credential, so callers can test
 * with a plain null check. A missing credential marks its source `skipped`; it
 * never prevents the endpoint from serving.
 */
function marqueeCredentials(): array
{
    // Spelled out one per line rather than looped, so that
    // `grep -rn "getenv\|\$_ENV" marquee/` shows every variable this tree reads.
    //
    // MEDIUX_API_KEY is deliberately not read: Mediux is not a source here.
    //
    // TVDB_API_KEY is the one variable this endpoint adds beyond the legacy set,
    // added deliberately: it is the only way to obtain TheTVDB artwork with real
    // provenance. Until it is set in the deployment, TheTVDB reports `skipped` and
    // everything else serves normally.
    return [
        'tmdb' => marqueeCredentialValue($_ENV['TMDB_API_KEY'] ?? getenv('TMDB_API_KEY')),
        'fanart' => marqueeCredentialValue($_ENV['FANART_API_KEY'] ?? getenv('FANART_API_KEY')),
        'tvdb' => marqueeCredentialValue($_ENV['TVDB_API_KEY'] ?? getenv('TVDB_API_KEY')),
        'access' => marqueeCredentialValue($_ENV['POSTERIA_API_KEY'] ?? getenv('POSTERIA_API_KEY')),
    ];
}

/** getenv() returns false for an unset variable; normalise that to null. */
function marqueeCredentialValue($value): ?string
{
    if ($value === false || $value === null || $value === '') {
        return null;
    }
    return (string) $value;
}
