<?php
// TMDB client.
//
// The endpoint set is carried over from api/fetch/posters/index.php, which had it
// right: search, details with external_ids appended, and a single /images call per
// work. What is not carried over is what the old code did with the search results.

if (!defined('MARQUEE_API_V1')) {
    http_response_code(404);
    exit;
}

function marqueeTmdbUrl(string $path, array $params, string $apiKey): string
{
    $params['api_key'] = $apiKey;
    return TMDB_BASE_URL . $path . '?' . http_build_query($params);
}

/** @param array $specs key => ['path' => string, 'params' => array] */
function marqueeTmdbFetchAll(array $specs, string $apiKey): array
{
    $requests = [];
    foreach ($specs as $key => $spec) {
        $requests[$key] = [
            'url' => marqueeTmdbUrl($spec['path'], $spec['params'] ?? [], $apiKey),
            'headers' => ['Accept: application/json'],
        ];
    }

    return MarqueeHttpClient::getInstance()->fetchAll($requests);
}

function marqueeTmdbFetch(string $path, array $params, string $apiKey): array
{
    $results = marqueeTmdbFetchAll(['one' => ['path' => $path, 'params' => $params]], $apiKey);
    return $results['one'];
}

/** The search endpoint for each request type. `season` searches shows. */
function marqueeTmdbSearchPath(string $type): string
{
    switch ($type) {
        case 'movie':
            return '/search/movie';
        case 'collection':
            return '/search/collection';
        default:
            return '/search/tv';
    }
}

function marqueeTmdbSearch(string $type, string $query, ?int $year, string $apiKey): array
{
    $params = [
        'query' => $query,
        'language' => 'en-US',
        'page' => 1,
    ];

    if ($type !== 'collection') {
        $params['include_adult'] = 'false';
    }

    // A year hint narrows TMDB's own result set, but it is only a hint: the
    // unfiltered search is what resolution scores against, so a wrong year in the
    // caller's metadata can never turn an exact title match into a no-match.
    if ($year !== null) {
        if ($type === 'movie') {
            $params['year'] = $year;
        } elseif ($type === 'show' || $type === 'season') {
            $params['first_air_date_year'] = $year;
        }
    }

    return marqueeTmdbFetch(marqueeTmdbSearchPath($type), $params, $apiKey);
}

/** Details and images for one resolved work, issued together. */
function marqueeTmdbWorkDetails(string $type, int $tmdbId, string $apiKey): array
{
    $specs = [];

    if ($type === 'movie') {
        $specs['details'] = [
            'path' => "/movie/{$tmdbId}",
            'params' => ['language' => 'en-US', 'append_to_response' => 'external_ids'],
        ];
        $specs['images'] = [
            'path' => "/movie/{$tmdbId}/images",
            'params' => ['include_image_language' => 'en,null'],
        ];
    } elseif ($type === 'collection') {
        $specs['details'] = [
            'path' => "/collection/{$tmdbId}",
            'params' => ['language' => 'en-US'],
        ];
        $specs['images'] = [
            'path' => "/collection/{$tmdbId}/images",
            'params' => ['include_image_language' => 'en,null'],
        ];
    } else {
        // Shows and seasons both need the show's details: external_ids is where
        // tvdb_id comes from, and fanart.tv's TV endpoint is keyed on it.
        $specs['details'] = [
            'path' => "/tv/{$tmdbId}",
            'params' => ['language' => 'en-US', 'append_to_response' => 'external_ids'],
        ];
        if ($type === 'show') {
            $specs['images'] = [
                'path' => "/tv/{$tmdbId}/images",
                'params' => ['include_image_language' => 'en,null'],
            ];
        }
    }

    return marqueeTmdbFetchAll($specs, $apiKey);
}

/** Season identity and season artwork, issued together. */
function marqueeTmdbSeason(int $showId, int $seasonNumber, string $apiKey): array
{
    return marqueeTmdbFetchAll([
        'details' => [
            'path' => "/tv/{$showId}/season/{$seasonNumber}",
            'params' => ['language' => 'en-US'],
        ],
        'images' => [
            'path' => "/tv/{$showId}/season/{$seasonNumber}/images",
            'params' => ['include_image_language' => 'en,null'],
        ],
    ], $apiKey);
}

/**
 * Map a TMDB /images payload to poster objects.
 *
 * TMDB returns width, height, iso_639_1 and vote_average per image. All four are
 * kept: they are free ranking and filtering signal. A field TMDB does not supply
 * is omitted rather than guessed — an unrated image carries no `score` at all, so
 * it sorts below rated ones instead of tying with a genuine zero.
 */
function marqueeTmdbPosters(?array $imagesPayload): array
{
    if (!is_array($imagesPayload) || !isset($imagesPayload['posters']) || !is_array($imagesPayload['posters'])) {
        return [];
    }

    $posters = [];
    foreach ($imagesPayload['posters'] as $image) {
        if (!is_array($image) || empty($image['file_path']) || !is_string($image['file_path'])) {
            continue;
        }

        $poster = [
            'url' => TMDB_IMAGE_BASE_URL . TMDB_SIZE_FULL . $image['file_path'],
            'thumb' => TMDB_IMAGE_BASE_URL . TMDB_SIZE_THUMB . $image['file_path'],
            'source' => SOURCE_LABELS['tmdb'],
        ];

        if (isset($image['width']) && is_numeric($image['width'])) {
            $poster['width'] = (int) $image['width'];
        }
        if (isset($image['height']) && is_numeric($image['height'])) {
            $poster['height'] = (int) $image['height'];
        }
        if (!empty($image['iso_639_1']) && is_string($image['iso_639_1'])) {
            $poster['language'] = $image['iso_639_1'];
        }
        if (isset($image['vote_average'], $image['vote_count'])
            && is_numeric($image['vote_average']) && (int) $image['vote_count'] > 0) {
            $poster['score'] = round((float) $image['vote_average'], 3);
        }

        $posters[] = $poster;
    }

    return $posters;
}
