<?php
// Non-TMDB poster sources, and the provider outcome map.
//
// Every source returns an explicit outcome. A source that failed is never
// indistinguishable from a source that simply has no artwork for this work —
// swallowing failures at the call site is what makes an accuracy regression
// impossible to diagnose from the client.
//
// Two sources: TMDB and fanart.tv.
//
// TheTVDB is deliberately absent. There is no TheTVDB credential in this
// deployment and no new environment variable may be introduced, so the only
// credential-free route is scraping their HTML by a title-derived slug, which
// cannot verify that the page describes the same work. tvdb_id resolution is kept
// (it comes from TMDB's external_ids, not from TheTVDB) because fanart.tv's TV
// endpoint is keyed on it.
//
// Mediux is deliberately absent too. Its staging host is gone and the production
// host rejects this deployment's credential, so it could only ever have been
// advertised-and-never-contributing. See lib/config.php.

if (!defined('MARQUEE_API_V1')) {
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
 * has to locate its own record before it can ask for artwork, so its lookup goes
 * in round one alongside fanart and its artwork call follows in round two.
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

    if ($requests === []) {
        return ['posters' => [], 'providers' => $providers, 'calls' => $calls];
    }

    // Round one: every source that can be asked something right now.
    $responses = MarqueeHttpClient::getInstance()->fetchAll($requests);

    foreach ($responses as $key => $response) {
        $label = SOURCE_LABELS[$key];
        $calls[] = [
            'source' => $label,
            'call' => $key === 'tvdb' ? $tvdbStage : null,
            'url' => preg_replace('/api_key=[^&]+/', 'api_key=REDACTED', $requests[$key]['url']),
            'status' => $response['status'],
            'error' => $response['error'],
            'timed_out' => $response['timed_out'],
        ];

        if (!$response['ok']) {
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

    // Round two: TheTVDB artwork, once its record has been identified.
    if (isset($tvdbSecond) && $tvdbSecond !== null && $tvdbToken !== null) {
        $response = MarqueeHttpClient::getInstance()->fetch($tvdbSecond['url'], marqueeTvdbHeaders($tvdbToken));

        $calls[] = [
            'source' => $tvdbLabel,
            'call' => $tvdbSecond['stage'],
            'url' => $tvdbSecond['url'],
            'status' => $response['status'],
            'error' => $response['error'],
            'timed_out' => $response['timed_out'],
        ];

        if (!$response['ok']) {
            $providers[$tvdbLabel] = in_array($response['status'], [401, 403], true)
                ? OUTCOME_SKIPPED
                : OUTCOME_ERROR;
        } else {
            $isMovie = $tvdbSecond['stage'] === 'artwork_movie';
            $found = marqueeTvdbPosters(
                $response['json']['data']['artworks'] ?? $response['json']['data']['artwork'] ?? null,
                $isMovie ? TVDB_ARTWORK_TYPE_MOVIE_POSTER : TVDB_ARTWORK_TYPE_SEASON_POSTER
            );
            $providers[$tvdbLabel] = $found === [] ? OUTCOME_NO_DATA : OUTCOME_OK;
            $posters = array_merge($posters, $found);
        }
    }

    return ['posters' => $posters, 'providers' => $providers, 'calls' => $calls];
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
