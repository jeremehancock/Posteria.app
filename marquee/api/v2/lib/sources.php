<?php
// Non-TMDB poster sources, and the provider outcome map.
//
// Every source returns an explicit outcome. A source that failed is never
// indistinguishable from a source that simply has no artwork for this work —
// swallowing failures at the call site is what makes an accuracy regression
// impossible to diagnose from the client.
//
// Non-TMDB sources here: fanart.tv, TheTVDB and TVmaze.
//
// TVmaze is the one source needing no credential, so it is the only one that can
// never be reported `skipped` for want of one. It indexes television only, so a
// movie or collection request reports it `no_data` without issuing a call.
//
// Mediux is deliberately absent. Its staging host is gone and the production host
// rejects this deployment's credential, so it could only ever have been
// advertised-and-never-contributing. See lib/config.php.
//
// MoviePosterDB is deliberately absent too, and for a different reason worth
// recording: its Data API is live and would satisfy every rule above, but it meters
// image delivery against the key stamped into the URLs it returns, and forbids
// proxying to avoid that. One Posteria key would fund every self-hosted install's
// browsing. Adding it needs a licensing conversation, not a code change.

if (!defined('MARQUEE_API_V2')) {
    http_response_code(404);
    exit;
}

const OUTCOME_OK = 'ok';
const OUTCOME_NO_DATA = 'no_data';
const OUTCOME_ERROR = 'error';
const OUTCOME_SKIPPED = 'skipped';

/**
 * Build the request set for every non-TMDB source, issue them concurrently, and
 * map the responses.
 *
 * Issued as up to two concurrent rounds. Most sources need one call, but TheTVDB
 * and TVmaze both have to locate their own record before they can ask for artwork,
 * so their lookups go in round one alongside fanart and their artwork calls follow
 * together in round two.
 *
 * @param array $ctx [
 *   'type' => string, 'tmdb_id' => int, 'tvdb_id' => ?int, 'imdb_id' => ?string,
 *   'season' => ?int, 'sources' => string[], 'credentials' => array
 * ]
 * @return array ['posters' => array, 'providers' => array, 'calls' => array]
 */
function marqueeGatherExternalPosters(array $ctx): array
{
    $credentials = $ctx['credentials'];
    $selected = $ctx['sources'];

    $providers = [];
    $requests = [];
    $calls = [];
    $posters = [];

    // --- fanart.tv -------------------------------------------------------
    $fanartLabel = SOURCE_LABELS['fanart'];
    if (!in_array('fanart', $selected, true)) {
        $providers[$fanartLabel] = OUTCOME_SKIPPED;
    } elseif ($credentials['fanart'] === null) {
        $providers[$fanartLabel] = OUTCOME_SKIPPED;
    } elseif ($ctx['type'] === 'collection') {
        // fanart.tv has no collection endpoint keyed on a TMDB collection id.
        $providers[$fanartLabel] = OUTCOME_NO_DATA;
    } elseif ($ctx['type'] === 'movie') {
        $requests['fanart'] = [
            'url' => FANART_BASE_URL . '/movies/' . $ctx['tmdb_id'] . '?api_key=' . urlencode($credentials['fanart']),
            'headers' => ['Accept: application/json'],
        ];
    } elseif ($ctx['tvdb_id'] === null) {
        // The identifier fanart's TV endpoint needs was not resolvable. That is an
        // absence of data, not a failure of fanart.
        $providers[$fanartLabel] = OUTCOME_NO_DATA;
    } else {
        $requests['fanart'] = [
            'url' => FANART_BASE_URL . '/tv/' . $ctx['tvdb_id'] . '?api_key=' . urlencode($credentials['fanart']),
            'headers' => ['Accept: application/json'],
        ];
    }

    // --- TheTVDB: locate the record ---------------------------------------
    $tvdbLabel = SOURCE_LABELS['tvdb'];
    $tvdbToken = null;
    $tvdbStage = null;

    if (!in_array('tvdb', $selected, true)) {
        $providers[$tvdbLabel] = OUTCOME_SKIPPED;
    } elseif ($ctx['type'] === 'collection') {
        // TheTVDB has no concept of a film collection.
        $providers[$tvdbLabel] = OUTCOME_NO_DATA;
    } else {
        $tvdbToken = marqueeTvdbToken($credentials['tvdb'], $loginResponse);

        if ($loginResponse !== null) {
            $calls[] = [
                'source' => $tvdbLabel,
                'call' => 'login',
                'status' => $loginResponse['status'],
                'error' => $loginResponse['error'],
                'timed_out' => $loginResponse['timed_out'],
            ];
        }

        if ($tvdbToken === null) {
            // Absent or rejected key, or an unreachable login. Treated the same way
            // as any other unusable credential.
            $providers[$tvdbLabel] = ($loginResponse !== null && !in_array($loginResponse['status'], [401, 403, 0], true))
                ? OUTCOME_ERROR
                : OUTCOME_SKIPPED;
            if ($providers[$tvdbLabel] === OUTCOME_SKIPPED && $credentials['tvdb'] !== null) {
                marqueeLogRequest(['event' => 'credential_rejected', 'source' => $tvdbLabel]);
            }
        } elseif ($ctx['type'] === 'movie') {
            if ($ctx['imdb_id'] === null) {
                // Without a remote id there is no way to identify the record, and
                // guessing from the title is exactly what this client exists to avoid.
                $providers[$tvdbLabel] = OUTCOME_NO_DATA;
            } else {
                $tvdbStage = 'locate_movie';
                $requests['tvdb'] = [
                    'url' => TVDB_BASE_URL . '/search/remoteid/' . urlencode($ctx['imdb_id']),
                    'headers' => marqueeTvdbHeaders($tvdbToken),
                ];
            }
        } elseif ($ctx['tvdb_id'] === null) {
            $providers[$tvdbLabel] = OUTCOME_NO_DATA;
        } elseif ($ctx['type'] === 'season') {
            $tvdbStage = 'locate_season';
            $requests['tvdb'] = [
                'url' => TVDB_BASE_URL . '/series/' . $ctx['tvdb_id'] . '/extended',
                'headers' => marqueeTvdbHeaders($tvdbToken),
            ];
        } else {
            // A show already has its TheTVDB id from TMDB, so artwork can be asked
            // for directly in this round.
            $tvdbStage = 'artwork_series';
            $requests['tvdb'] = [
                'url' => TVDB_BASE_URL . '/series/' . $ctx['tvdb_id'] . '/artworks?type=' . TVDB_ARTWORK_TYPE_SERIES_POSTER,
                'headers' => marqueeTvdbHeaders($tvdbToken),
            ];
        }
    }

    // --- TVmaze: locate the record ----------------------------------------
    $tvmazeLabel = SOURCE_LABELS['tvmaze'];
    $tvmazePage = null;
    $tvmazeSecond = null;

    if (!in_array('tvmaze', $selected, true)) {
        $providers[$tvmazeLabel] = OUTCOME_SKIPPED;
    } elseif (!marqueeSourceHandlesType('tvmaze', $ctx['type'])) {
        // Television only. Asking would spend a round trip and a slice of a shared
        // per-IP rate limit on a question the source cannot answer.
        $providers[$tvmazeLabel] = OUTCOME_NO_DATA;
    } else {
        $lookupUrl = marqueeTvmazeLookupUrl($ctx['tvdb_id'], $ctx['imdb_id']);
        if ($lookupUrl === null) {
            // Neither remote id resolved, and a title search is not an option here.
            $providers[$tvmazeLabel] = OUTCOME_NO_DATA;
        } else {
            $requests['tvmaze'] = [
                'url' => $lookupUrl,
                'headers' => ['Accept: application/json'],
            ];
        }
    }

    if ($requests === []) {
        return ['posters' => [], 'providers' => $providers, 'calls' => $calls];
    }

    // Round one: every source that can be asked something right now.
    $responses = MarqueeHttpClient::getInstance()->fetchAll($requests);

    foreach ($responses as $key => $response) {
        $label = SOURCE_LABELS[$key];
        $stageLabels = ['tvdb' => $tvdbStage, 'tvmaze' => 'lookup'];
        $calls[] = [
            'source' => $label,
            'call' => $stageLabels[$key] ?? null,
            'url' => preg_replace('/api_key=[^&]+/', 'api_key=REDACTED', $requests[$key]['url']),
            'status' => $response['status'],
            'error' => $response['error'],
            'timed_out' => $response['timed_out'],
        ];

        if (!$response['ok']) {
            // TVmaze answers 404 when it holds no show for the identifier. That is
            // an absence of data, not an outage, and reporting it as `error` would
            // mark the response `partial` and stop it being cached — for every show
            // TVmaze simply does not carry.
            if ($key === 'tvmaze' && $response['status'] === 404) {
                $providers[$label] = OUTCOME_NO_DATA;
                continue;
            }

            // A rejected credential is not an outage: the source will fail
            // identically on every request until someone sets a working key, so
            // reporting `error` would mark every response `partial` and, because
            // partial responses are never cached, would quietly disable caching
            // for the whole endpoint. `skipped` is the honest reading — the
            // deployment has no usable credential for this source — and it starts
            // working the moment a valid key is configured, with no code change.
            if (in_array($response['status'], [401, 403], true)) {
                $providers[$label] = OUTCOME_SKIPPED;
                marqueeLogRequest([
                    'event' => 'credential_rejected',
                    'source' => $label,
                    'status' => $response['status'],
                ]);
                continue;
            }

            $providers[$label] = OUTCOME_ERROR;
            continue;
        }

        if ($key === 'fanart') {
            $found = marqueeMapFanartPosters($response['json'], $ctx['type'], $ctx['season']);
            $providers[$label] = $found === [] ? OUTCOME_NO_DATA : OUTCOME_OK;
            $posters = array_merge($posters, $found);
            continue;
        }

        if ($key === 'tvmaze') {
            // The lookup redirects to the show record, which the client follows, so
            // this response is the show itself: its id addresses the artwork, and
            // its url is the attribution link the CC BY-SA licence requires.
            $showId = marqueeTvmazeShowId($response['json']);
            $tvmazePage = marqueeTvmazeShowPage($response['json']);

            if ($showId === null) {
                $providers[$label] = OUTCOME_NO_DATA;
                continue;
            }

            $tvmazeSecond = $ctx['type'] === 'season'
                ? ['stage' => 'seasons', 'url' => TVMAZE_BASE_URL . '/shows/' . $showId . '/seasons']
                : ['stage' => 'images', 'url' => TVMAZE_BASE_URL . '/shows/' . $showId . '/images'];
            continue;
        }

        // TheTVDB: either this response was the artwork itself, or it tells us
        // which record to ask for artwork next.
        if ($tvdbStage === 'artwork_series') {
            $found = marqueeTvdbPosters($response['json']['data']['artworks'] ?? null, TVDB_ARTWORK_TYPE_SERIES_POSTER);
            $providers[$label] = $found === [] ? OUTCOME_NO_DATA : OUTCOME_OK;
            $posters = array_merge($posters, $found);
            continue;
        }

        if ($tvdbStage === 'locate_movie') {
            $movieId = marqueeTvdbFindByRemoteId($response['json'], 'movie');
            $tvdbSecond = $movieId === null ? null : [
                'stage' => 'artwork_movie',
                'url' => TVDB_BASE_URL . '/movies/' . $movieId . '/extended',
            ];
        } else {
            $seasonId = marqueeTvdbSeasonId($response['json'], $ctx['season']);
            $tvdbSecond = $seasonId === null ? null : [
                'stage' => 'artwork_season',
                'url' => TVDB_BASE_URL . '/seasons/' . $seasonId . '/extended',
            ];
        }

        if ($tvdbSecond === null) {
            // The work has no TheTVDB record, or the season does not exist there.
            $providers[$label] = OUTCOME_NO_DATA;
        }
    }

    // Round two: artwork from the sources that had to identify their record first.
    // Issued together rather than one after the other, for the same reason round one
    // is concurrent — the deployed server is serial, so two sequential round trips
    // would be two clients' worth of waiting.
    $secondRequests = [];
    $secondStages = [];

    if (isset($tvdbSecond) && $tvdbSecond !== null && $tvdbToken !== null) {
        $secondRequests['tvdb'] = [
            'url' => $tvdbSecond['url'],
            'headers' => marqueeTvdbHeaders($tvdbToken),
        ];
        $secondStages['tvdb'] = $tvdbSecond['stage'];
    }

    if ($tvmazeSecond !== null) {
        $secondRequests['tvmaze'] = [
            'url' => $tvmazeSecond['url'],
            'headers' => ['Accept: application/json'],
        ];
        $secondStages['tvmaze'] = $tvmazeSecond['stage'];
    }

    if ($secondRequests === []) {
        return ['posters' => $posters, 'providers' => $providers, 'calls' => $calls];
    }

    $secondResponses = MarqueeHttpClient::getInstance()->fetchAll($secondRequests);

    foreach ($secondResponses as $key => $response) {
        $label = SOURCE_LABELS[$key];
        $stage = $secondStages[$key];

        $calls[] = [
            'source' => $label,
            'call' => $stage,
            'url' => $secondRequests[$key]['url'],
            'status' => $response['status'],
            'error' => $response['error'],
            'timed_out' => $response['timed_out'],
        ];

        if (!$response['ok']) {
            $providers[$label] = in_array($response['status'], [401, 403], true)
                ? OUTCOME_SKIPPED
                : OUTCOME_ERROR;
            continue;
        }

        if ($key === 'tvdb') {
            $isMovie = $stage === 'artwork_movie';
            $found = marqueeTvdbPosters(
                $response['json']['data']['artworks'] ?? $response['json']['data']['artwork'] ?? null,
                $isMovie ? TVDB_ARTWORK_TYPE_MOVIE_POSTER : TVDB_ARTWORK_TYPE_SEASON_POSTER
            );
        } elseif ($stage === 'seasons') {
            $found = marqueeTvmazeSeasonPosters($response['json'], $ctx['season']);
        } else {
            $found = marqueeTvmazePosters($response['json'], $tvmazePage);
        }

        $providers[$label] = $found === [] ? OUTCOME_NO_DATA : OUTCOME_OK;
        $posters = array_merge($posters, $found);
    }

    return ['posters' => $posters, 'providers' => $providers, 'calls' => $calls];
}

/**
 * Whether a source indexes a given media type at all.
 *
 * A source that structurally cannot hold the answer is reported `no_data` without a
 * call. This is distinct from a source that was asked and had nothing — the two are
 * indistinguishable in `providers` today, which is a known limitation recorded in
 * the change's design rather than an oversight.
 */
function marqueeSourceHandlesType(string $source, string $type): bool
{
    if (in_array($source, TELEVISION_ONLY_SOURCES, true)) {
        return $type === 'show' || $type === 'season';
    }

    return true;
}

/**
 * Classify a providers map into a response verdict.
 *
 * `ok`         — nothing that was queried failed.
 * `partial`    — some queried sources failed; the response still carries what was
 *                retrieved, and says so, so sparse artwork is never mistaken for
 *                an outage.
 * `all_failed` — every queried source failed; there is nothing to return.
 *
 * `skipped` sources are not "queried" and never count either way, so excluding a
 * source with `sources=`, or having no usable credential for it, cannot by itself
 * make a response partial.
 */
function marqueeSummariseProviders(array $providers): string
{
    $queried = array_filter($providers, static fn($outcome) => $outcome !== OUTCOME_SKIPPED);
    if ($queried === []) {
        return 'ok';
    }

    $errored = array_filter($queried, static fn($outcome) => $outcome === OUTCOME_ERROR);
    if ($errored === []) {
        return 'ok';
    }

    return count($errored) === count($queried) ? 'all_failed' : 'partial';
}

/**
 * Map a fanart.tv payload.
 *
 * A show request takes tvposter only, and a season request takes seasonposter
 * entries filtered to the requested season. Season artwork never leaks into a show
 * response and show artwork never leaks into a season response, and a fanart image
 * is always attributed to fanart.tv rather than to whichever source supplied the
 * work's metadata.
 */
function marqueeMapFanartPosters(?array $payload, string $type, ?int $season): array
{
    if (!is_array($payload)) {
        return [];
    }

    if ($type === 'movie') {
        $entries = $payload['movieposter'] ?? [];
    } elseif ($type === 'season') {
        $entries = $payload['seasonposter'] ?? [];
    } else {
        $entries = $payload['tvposter'] ?? [];
    }

    if (!is_array($entries)) {
        return [];
    }

    $posters = [];
    foreach ($entries as $entry) {
        if (!is_array($entry) || empty($entry['url']) || !is_string($entry['url'])) {
            continue;
        }

        if ($type === 'season') {
            // fanart reports the season as a string, "0" for Specials, and uses
            // "all" for artwork that covers the whole run.
            $entrySeason = $entry['season'] ?? null;
            if (!is_numeric($entrySeason) || (int) $entrySeason !== $season) {
                continue;
            }
        }

        $poster = [
            'url' => $entry['url'],
            'source' => SOURCE_LABELS['fanart'],
        ];

        // fanart uses "00" for language-neutral artwork.
        if (!empty($entry['lang']) && is_string($entry['lang']) && $entry['lang'] !== '00') {
            $poster['language'] = $entry['lang'];
        }

        // fanart supplies a like count, not a rating comparable to TMDB's
        // vote_average. Omitting `score` is more honest than inventing one.
        $posters[] = $poster;
    }

    return $posters;
}
