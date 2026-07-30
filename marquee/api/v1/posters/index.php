<?php
// GET /marquee/api/v1/posters
//
// Poster search for Marquee. Resolves the query to exactly one work, then gathers
// artwork for that work only.
//
// Entirely self-contained: nothing here reads, includes or depends on anything
// under api/. Where this endpoint needs the same provider integration as the
// legacy one, the code is duplicated on purpose — sharing it would couple the two
// and put the endpoint that serves deployed clients at risk.

define('MARQUEE_API_V1', true);

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/store.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/request.php';
require_once __DIR__ . '/../lib/tmdb.php';
require_once __DIR__ . '/../lib/resolve.php';
require_once __DIR__ . '/../lib/tvdb.php';
require_once __DIR__ . '/../lib/sources.php';
require_once __DIR__ . '/../lib/posters.php';

marqueeSendCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Preflight is answered before any identification check: a browser cannot attach
// custom headers to a preflight.
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($method !== 'GET') {
    marqueeSendFailure('method_not_allowed', 'This endpoint accepts GET requests only.');
}

$credentials = marqueeCredentials();
$client = marqueeIdentifyClient($credentials);
marqueeEnforceRateLimit(marqueeClientIp());

$request = marqueeParseRequest($_GET);
$startedAt = microtime(true);

// TMDB is the resolution provider as well as an artwork source: every other
// source is keyed on an identifier that only TMDB can supply. So a request that
// excludes `tmdb` still resolves through TMDB; it just gets no TMDB artwork.
if ($credentials['tmdb'] === null) {
    marqueeSendFailure(
        'upstream_unavailable',
        'Poster search is unavailable: the TMDB credential is not configured on this deployment.'
    );
}

$includeTmdbArtwork = in_array('tmdb', $request['sources'], true);

// --- Resolve ------------------------------------------------------------
// The resolution is cached separately from the artwork so that a repeat search
// skips the search call too, not just the artwork fan-out.
// Normalised for the type, so the two vocabularies for one collection — the
// media server's "Star Wars" and the provider's "Star Wars Collection" — share a
// single entry rather than each paying for its own search.
$resolutionKey = 'resolve:' . $request['type'] . ':'
    . marqueeNormaliseTitleForType($request['q'], $request['type'])
    . ':' . ($request['year'] ?? '');

$resolution = marqueeStoreGet($resolutionKey);

if (!is_array($resolution)) {
    $searchResponse = marqueeTmdbSearch($request['type'], $request['q'], $request['year'], $credentials['tmdb']);

    if (!$searchResponse['ok']) {
        marqueeSendFailure(
            'upstream_unavailable',
            'The search provider could not be reached: ' . ($searchResponse['error'] ?? 'unknown error') . '.'
        );
    }

    $resolutionDiagnostics = null;
    $resolution = marqueeResolveWork(
        $searchResponse['json'],
        $request['type'],
        $request['q'],
        $request['year'],
        $resolutionDiagnostics
    );

    if ($resolution === null) {
        // The failure path carries debug too. This is the case where it earns its
        // keep: without the scores, "no match" cannot be told apart from "matched
        // and scored below the floor", which is a question about this endpoint's
        // configuration rather than about the query.
        $extra = [];
        if ($request['debug']) {
            $extra['debug'] = [
                'resolution' => $resolutionDiagnostics,
                'calls' => [[
                    'source' => SOURCE_LABELS['tmdb'],
                    'call' => 'search',
                    'status' => $searchResponse['status'],
                    'error' => $searchResponse['error'],
                    'timed_out' => $searchResponse['timed_out'],
                ]],
            ];
        }

        marqueeSendFailure(
            'no_match',
            'No ' . $request['type'] . ' matched "' . $request['q'] . '".',
            $extra
        );
    }

    marqueeStoreSet($resolutionKey, $resolution, CACHE_TTL_SECONDS);
}

$winner = $resolution['winner'];

// --- Artwork cache ------------------------------------------------------
// Keyed on the resolved work rather than the raw query, so different spellings
// and year hints that land on the same work share one entry.
$sortedSources = $request['sources'];
sort($sortedSources);
$cacheKey = 'posters:' . $request['type'] . ':' . $winner['tmdb_id']
    . ':' . ($request['season'] ?? 'none')
    . ':' . implode(',', $sortedSources)
    . ':' . $request['limit'];

$cached = marqueeStoreGet($cacheKey);
if (is_array($cached) && isset($cached['success'])) {
    if ($request['debug']) {
        // `calls` is present but empty rather than absent: a cache hit made no
        // upstream calls, and the debug shape should not change between a hit and
        // a miss.
        $cached['debug'] = ['cache' => 'hit', 'resolution' => $resolution, 'calls' => []];
    }
    marqueeLogRequest([
        'client' => $client['name'],
        'version' => $client['version'],
        'query' => $request,
        'match' => $cached['match'] ?? null,
        'providers' => $cached['providers'] ?? null,
        'cache' => 'hit',
        'ms' => (int) round((microtime(true) - $startedAt) * 1000),
    ]);
    marqueeSendSuccess($cached);
}

// --- Gather -------------------------------------------------------------
$workResponses = marqueeTmdbWorkDetails($request['type'], $winner['tmdb_id'], $credentials['tmdb']);
$details = ($workResponses['details']['ok'] ?? false) ? $workResponses['details']['json'] : null;

$match = marqueeBuildMatch($request['type'], $winner, $details);

$tmdbPosters = [];
$tmdbFailed = !($workResponses['details']['ok'] ?? false);
$debugCalls = [];

foreach ($workResponses as $key => $response) {
    $debugCalls[] = [
        'source' => SOURCE_LABELS['tmdb'],
        'call' => $key,
        'status' => $response['status'],
        'error' => $response['error'],
        'timed_out' => $response['timed_out'],
    ];
}

if ($request['type'] === 'season') {
    // A season request resolves the show, then the season within it. There is
    // nowhere in this response to put show artwork, which is the point: a season
    // query returns season artwork or it returns nothing.
    $seasonResponses = marqueeTmdbSeason($winner['tmdb_id'], $request['season'], $credentials['tmdb']);

    foreach ($seasonResponses as $key => $response) {
        $debugCalls[] = [
            'source' => SOURCE_LABELS['tmdb'],
            'call' => 'season.' . $key,
            'status' => $response['status'],
            'error' => $response['error'],
            'timed_out' => $response['timed_out'],
        ];
    }

    // TMDB answers 404 for a season a show does not have. That is a resolution
    // failure, not an upstream failure.
    if (($seasonResponses['details']['status'] ?? 0) === 404) {
        // The other no_match exit. It reports the show that did resolve rather
        // than a candidate list, so the reader can see the season was asked for
        // against the right work.
        $extra = [];
        if ($request['debug']) {
            $extra['debug'] = [
                'resolution' => [
                    'winner' => [
                        'tmdb_id' => $winner['tmdb_id'],
                        'title' => $match['title'],
                        'year' => $match['year'],
                        'score' => $winner['score'] ?? $resolution['score'],
                    ],
                    'season_requested' => $request['season'],
                ],
                'calls' => $debugCalls,
            ];
        }

        marqueeSendFailure(
            'no_match',
            'Season ' . $request['season'] . ' was not found for "' . $match['title'] . '".',
            $extra
        );
    }

    $seasonDetails = ($seasonResponses['details']['ok'] ?? false) ? $seasonResponses['details']['json'] : null;
    $match['season'] = marqueeBuildSeasonIdentity($request['season'], $seasonDetails);

    $tmdbFailed = $tmdbFailed || !($seasonResponses['images']['ok'] ?? false);
    if ($seasonResponses['images']['ok'] ?? false) {
        $tmdbPosters = marqueeTmdbPosters($seasonResponses['images']['json']);
    }
} else {
    $tmdbFailed = $tmdbFailed || !($workResponses['images']['ok'] ?? false);
    if ($workResponses['images']['ok'] ?? false) {
        $tmdbPosters = marqueeTmdbPosters($workResponses['images']['json']);
    }
}

$providers = [];
if (!$includeTmdbArtwork) {
    $providers[SOURCE_LABELS['tmdb']] = OUTCOME_SKIPPED;
    $tmdbPosters = [];
} elseif ($tmdbFailed) {
    $providers[SOURCE_LABELS['tmdb']] = OUTCOME_ERROR;
} else {
    $providers[SOURCE_LABELS['tmdb']] = $tmdbPosters === [] ? OUTCOME_NO_DATA : OUTCOME_OK;
}

$external = marqueeGatherExternalPosters([
    'type' => $request['type'],
    'tmdb_id' => $winner['tmdb_id'],
    'tvdb_id' => $match['tvdb_id'],
    'imdb_id' => $match['imdb_id'],
    'season' => $request['season'],
    'sources' => $request['sources'],
    'credentials' => $credentials,
]);

$providers = array_merge($providers, $external['providers']);
$debugCalls = array_merge($debugCalls, $external['calls']);

// --- Outcome ------------------------------------------------------------
$verdict = marqueeSummariseProviders($providers);

if ($verdict === 'all_failed') {
    marqueeSendFailure(
        'upstream_unavailable',
        'Every poster source failed for this request.',
        ['providers' => $providers]
    );
}

$assembled = marqueeAssemblePosters(array_merge($tmdbPosters, $external['posters']), $request['limit']);

$payload = [
    'success' => true,
    'query' => [
        'q' => $request['q'],
        'type' => $request['type'],
        'season' => $request['season'],
    ],
    'match' => $match,
    'posters' => $assembled['posters'],
    'total' => $assembled['total'],
    'providers' => $providers,
];

// `code` on a success response is not a failure marker — it reports that some
// sources failed, so a client can tell sparse artwork from a provider outage.
if ($verdict === 'partial') {
    $payload = array_merge(
        ['success' => true, 'code' => 'partial'],
        array_slice($payload, 1, null, true)
    );
} else {
    // A partial result would poison the cache for everyone behind it.
    marqueeStoreSet($cacheKey, $payload, CACHE_TTL_SECONDS);
}

marqueeLogRequest([
    'client' => $client['name'],
    'version' => $client['version'],
    'query' => $request,
    'match' => ['tmdb_id' => $match['tmdb_id'], 'title' => $match['title'], 'year' => $match['year']],
    'providers' => $providers,
    'total' => $assembled['total'],
    'cache' => 'miss',
    'ms' => (int) round((microtime(true) - $startedAt) * 1000),
]);

if ($request['debug']) {
    $payload['debug'] = [
        'cache' => 'miss',
        'resolution' => [
            'query_normalised' => marqueeNormaliseTitleForType($request['q'], $request['type']),
            'winner' => [
                'tmdb_id' => $winner['tmdb_id'],
                'title' => $winner['title'],
                'year' => $winner['year'],
                'score' => $winner['score'] ?? $resolution['score'],
            ],
            'rejected' => $resolution['rejected'],
            'score_floor' => RESOLVE_SCORE_FLOOR,
        ],
        'calls' => $debugCalls,
        'ms' => (int) round((microtime(true) - $startedAt) * 1000),
    ];
}

marqueeSendSuccess($payload);
