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

// The interpreted request, echoed back on every success.
//
// Built once and applied to cached responses as well as fresh ones. The artwork
// cache is keyed on the resolved work, not on the text that found it, so a cached
// payload carries the `query` block of whichever request happened to populate it —
// a different `q`, and a different `tmdb_id`, belonging to a different caller.
//
// `query.tmdb_id` is what this caller sent, or null. `match.tmdb_id` is what the
// response describes. Clients compare the two to detect an identifier that has
// gone stale, so serving a cached echo silently breaks that check.
$queryEcho = [
    'q' => $request['q'],
    'type' => $request['type'],
    'tmdb_id' => $request['tmdb_id'],
    'season' => $request['season'],
];

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

/**
 * Resolve the request's title to one work, or fail the request.
 *
 * Extracted because it serves two callers: a request that supplied no
 * identifier, and one whose identifier the provider turned out not to have.
 * Never returns on a no-match — it emits the 404 and exits.
 *
 * @return array{winner: array, score: float, rejected: array}
 */
function marqueeResolveRequestByTitle(array $request, array $credentials): array
{
    // The resolution is cached separately from the artwork so that a repeat search
    // skips the search call too, not just the artwork fan-out.
    // Normalised for the type, so the two vocabularies for one collection — the
    // media server's "Star Wars" and the provider's "Star Wars Collection" — share
    // a single entry rather than each paying for its own search.
    $resolutionKey = 'resolve:' . $request['type'] . ':'
        . marqueeNormaliseTitleForType($request['q'], $request['type'])
        . ':' . ($request['year'] ?? '');

    $resolution = marqueeStoreGet($resolutionKey);
    if (is_array($resolution)) {
        return $resolution;
    }

    $searchResponse = marqueeTmdbSearch($request['type'], $request['q'], $request['year'], $credentials['tmdb']);

    if (!$searchResponse['ok']) {
        marqueeSendFailure(
            'upstream_unavailable',
            'The search provider could not be reached: ' . ($searchResponse['error'] ?? 'unknown error') . '.'
        );
    }

    $diagnostics = null;
    $resolution = marqueeResolveWork(
        $searchResponse['json'],
        $request['type'],
        $request['q'],
        $request['year'],
        $diagnostics
    );

    if ($resolution === null) {
        // The failure path carries debug too. This is the case where it earns its
        // keep: without the scores, "no match" cannot be told apart from "matched
        // and scored below the floor", which is a question about this endpoint's
        // configuration rather than about the query.
        $extra = [];
        if ($request['debug']) {
            $extra['debug'] = [
                'identified_by' => $request['tmdb_id'] === null ? 'title' : 'tmdb_id_unknown_then_title',
                'resolution' => $diagnostics,
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

    return $resolution;
}

// --- Identify -----------------------------------------------------------
// A supplied identifier makes resolution unnecessary: resolution's only output
// is a tmdb_id, and everything downstream keys off it. The client's title is
// carried as a placeholder until the provider's details arrive, after which
// marqueeBuildMatch() replaces it with the real one.
if ($request['tmdb_id'] !== null) {
    $identifiedBy = 'tmdb_id';
    $resolution = null;
    $winner = [
        'tmdb_id' => $request['tmdb_id'],
        'title' => $request['q'],
        'year' => null,
        'score' => null,
    ];
} else {
    $identifiedBy = 'title';
    $resolution = marqueeResolveRequestByTitle($request, $credentials);
    $winner = $resolution['winner'];
}

// The cache lookup and the details call run once normally, and a second time
// only when a supplied identifier turns out to be unknown upstream.
$attempt = 0;

while (true) {
    $attempt++;

    // --- Artwork cache --------------------------------------------------
    // Keyed on the resolved work rather than the raw query, so different
    // spellings, year hints and a supplied identifier all share one entry.
    $sortedSources = $request['sources'];
    sort($sortedSources);
    $cacheKey = 'posters:' . $request['type'] . ':' . $winner['tmdb_id']
        . ':' . ($request['season'] ?? 'none')
        . ':' . implode(',', $sortedSources)
        . ':' . $request['limit'];

    $cached = marqueeStoreGet($cacheKey);
    if (is_array($cached) && isset($cached['success'])) {
        // Everything else in a cached payload describes the resolved work and the
        // shaping parameters, both of which are in the cache key. `query` is the
        // one part that belongs to the caller rather than to the work.
        //
        // Rebuilt rather than assigned so `query` keeps its position in the
        // response, and so an entry stored before it was dropped is corrected
        // rather than trusted — the left operand of `+` wins on a key clash.
        $cached = ['success' => $cached['success'], 'query' => $queryEcho] + $cached;

        if ($request['debug']) {
            // `calls` is present but empty rather than absent: a cache hit made no
            // upstream calls, and the debug shape should not change between a hit
            // and a miss.
            $cached['debug'] = [
                'cache' => 'hit',
                'identified_by' => $identifiedBy,
                'resolution' => $resolution,
                'calls' => [],
            ];
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

    // --- Gather ---------------------------------------------------------
    $workResponses = marqueeTmdbWorkDetails($request['type'], $winner['tmdb_id'], $credentials['tmdb']);

    // A 404 on details means the provider does not have that identifier: the
    // client's stored id is stale, so fall back to the title it also sent.
    //
    // Only a 404 does this. A timeout or a 5xx may well be a perfectly good id
    // behind an unreachable provider, and searching by title there could serve a
    // different work's artwork during an outage — those stay with the ordinary
    // provider-failure handling below.
    //
    // A work that is found but simply has no artwork is NOT this case. It is a
    // success with an empty poster list, and falling back would gather some other
    // work's posters and present them under this item's identity.
    if (
        $attempt === 1
        && $identifiedBy === 'tmdb_id'
        && ($workResponses['details']['status'] ?? 0) === 404
    ) {
        $identifiedBy = 'tmdb_id_unknown_then_title';
        $resolution = marqueeResolveRequestByTitle($request, $credentials);
        $winner = $resolution['winner'];
        continue;
    }

    break;
}

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
                'identified_by' => $identifiedBy,
                'resolution' => [
                    'winner' => [
                        'tmdb_id' => $winner['tmdb_id'],
                        'title' => $match['title'],
                        'year' => $match['year'],
                        // Null when the work was identified by id: nothing was scored.
                        'score' => $winner['score'] ?? ($resolution['score'] ?? null),
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
    'query' => $queryEcho,
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
    //
    // `query` is dropped from the stored copy rather than merely overwritten on
    // read: the entry is shared by every caller who resolves to this work, and
    // there is no reason to persist one caller's search text in it.
    $toCache = $payload;
    unset($toCache['query']);
    marqueeStoreSet($cacheKey, $toCache, CACHE_TTL_SECONDS);
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
        // How the work was identified: `tmdb_id` (no title search was made),
        // `tmdb_id_unknown_then_title` (the supplied id was not found upstream), or
        // `title`.
        'identified_by' => $identifiedBy,
        'resolution' => [
            'query_normalised' => marqueeNormaliseTitleForType($request['q'], $request['type']),
            'winner' => [
                'tmdb_id' => $winner['tmdb_id'],
                'title' => $winner['title'],
                'year' => $winner['year'],
                // Both null when identified by id: no candidate was ever scored.
                'score' => $winner['score'] ?? ($resolution['score'] ?? null),
            ],
            'rejected' => $resolution['rejected'] ?? [],
            'score_floor' => RESOLVE_SCORE_FLOOR,
        ],
        'calls' => $debugCalls,
        'ms' => (int) round((microtime(true) - $startedAt) * 1000),
    ];
}

marqueeSendSuccess($payload);
