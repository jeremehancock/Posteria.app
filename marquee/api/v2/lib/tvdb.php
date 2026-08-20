<?php
// TheTVDB v4 client.
//
// The legacy endpoint obtained TheTVDB artwork by turning a title into a slug,
// fetching thetvdb.com as HTML and regex-scraping image URLs out of the page. That
// has no provenance: nothing checks the page describes the work being searched
// for, so the same 20 images were attached to three different works at once.
//
// This client never uses a title. Records are located by remote id — IMDb for
// movies, and for shows the TVDB id TMDB already hands us in external_ids — so an
// artwork set is always provably the requested work's, or there is no artwork set.
//
// Requires TVDB_API_KEY. Without it TheTVDB reports `skipped` and everything else
// serves normally.

if (!defined('MARQUEE_API_V2')) {
    http_response_code(404);
    exit;
}

/**
 * Exchange the API key for a bearer token, cached until shortly before it expires.
 *
 * Issued tokens last about a month, so this is one extra round trip roughly every
 * three weeks rather than one per request.
 *
 * @return string|null null when the key is absent or rejected
 */
function marqueeTvdbToken(?string $apiKey, ?array &$loginResponse = null): ?string
{
    $loginResponse = null;

    if ($apiKey === null) {
        return null;
    }

    $cacheKey = 'tvdb:token:' . substr(hash('sha256', $apiKey), 0, 16);
    $cached = marqueeStoreGet($cacheKey);
    if (is_string($cached) && $cached !== '') {
        return $cached;
    }

    $loginResponse = MarqueeHttpClient::getInstance()->postJson(
        TVDB_BASE_URL . '/login',
        ['apikey' => $apiKey],
        ['Accept: application/json']
    );

    if (!$loginResponse['ok']) {
        return null;
    }

    $token = $loginResponse['json']['data']['token'] ?? null;
    if (!is_string($token) || $token === '') {
        return null;
    }

    marqueeStoreSet($cacheKey, $token, TVDB_TOKEN_TTL_SECONDS);
    return $token;
}

function marqueeTvdbHeaders(string $token): array
{
    return ['Authorization: Bearer ' . $token, 'Accept: application/json'];
}

/**
 * Find the TheTVDB record id for a work, by remote id.
 *
 * A remote-id lookup can return more than one record — a numeric TMDB id happily
 * collides with some other provider's id for an unrelated work, so searching for
 * the TMDB movie id 603 returns both the film "The Matrix" and the series
 * "Veronica's Closet". The requested record type is therefore mandatory, not a
 * refinement: without it this reintroduces exactly the wrong-work bug the client
 * exists to avoid.
 *
 * @param string $recordType 'movie' or 'series'
 * @return int|null
 */
function marqueeTvdbFindByRemoteId(?array $payload, string $recordType): ?int
{
    if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
        return null;
    }

    foreach ($payload['data'] as $row) {
        if (!is_array($row) || !isset($row[$recordType]) || !is_array($row[$recordType])) {
            continue;
        }
        $id = $row[$recordType]['id'] ?? null;
        if (is_numeric($id)) {
            return (int) $id;
        }
    }

    return null;
}

/**
 * TheTVDB reports languages as ISO 639-3 ("eng"); TMDB uses 639-1 ("en"). They are
 * compared against each other during ranking, so normalise to 639-1. An unmapped
 * code is passed through rather than guessed at.
 */
function marqueeTvdbLanguage(?string $code): ?string
{
    if ($code === null || $code === '') {
        return null;
    }

    static $map = [
        'eng' => 'en', 'spa' => 'es', 'fra' => 'fr', 'fre' => 'fr', 'deu' => 'de',
        'ger' => 'de', 'ita' => 'it', 'por' => 'pt', 'rus' => 'ru', 'jpn' => 'ja',
        'kor' => 'ko', 'zho' => 'zh', 'chi' => 'zh', 'nld' => 'nl', 'dut' => 'nl',
        'swe' => 'sv', 'nor' => 'no', 'dan' => 'da', 'fin' => 'fi', 'pol' => 'pl',
        'tur' => 'tr', 'ara' => 'ar', 'heb' => 'he', 'hin' => 'hi', 'ces' => 'cs',
        'cze' => 'cs', 'hun' => 'hu', 'ell' => 'el', 'gre' => 'el', 'tha' => 'th',
        'vie' => 'vi', 'ukr' => 'uk', 'ron' => 'ro', 'rum' => 'ro', 'bul' => 'bg',
    ];

    return $map[strtolower($code)] ?? $code;
}

/**
 * Map TheTVDB artwork entries of one type to poster objects.
 *
 * TheTVDB's `score` runs in the hundred-thousands and is not comparable to TMDB's
 * 0–10 `vote_average`. Carrying it across would put every TheTVDB poster above
 * every TMDB one regardless of quality, so it is omitted — the same reasoning that
 * omits fanart's like count.
 */
function marqueeTvdbPosters($artworks, int $wantedType): array
{
    if (!is_array($artworks)) {
        return [];
    }

    $posters = [];
    foreach ($artworks as $artwork) {
        if (!is_array($artwork) || (int) ($artwork['type'] ?? 0) !== $wantedType) {
            continue;
        }
        if (empty($artwork['image']) || !is_string($artwork['image'])) {
            continue;
        }

        $poster = [
            'url' => $artwork['image'],
            'source' => SOURCE_LABELS['tvdb'],
        ];

        if (!empty($artwork['thumbnail']) && is_string($artwork['thumbnail'])) {
            $poster['thumb'] = $artwork['thumbnail'];
        }

        $language = marqueeTvdbLanguage($artwork['language'] ?? null);
        if ($language !== null) {
            $poster['language'] = $language;
        }

        if (isset($artwork['width']) && is_numeric($artwork['width'])) {
            $poster['width'] = (int) $artwork['width'];
        }
        if (isset($artwork['height']) && is_numeric($artwork['height'])) {
            $poster['height'] = (int) $artwork['height'];
        }

        $posters[] = $poster;
    }

    return $posters;
}

/**
 * Pick the TheTVDB season record for a season number.
 *
 * Only "official" seasons are considered: TheTVDB also carries DVD, absolute and
 * alternate orderings whose numbering does not line up with the season number the
 * caller derived from Plex. Season 0 is Specials and is matched as a real value.
 */
function marqueeTvdbSeasonId(?array $seriesExtended, int $seasonNumber): ?int
{
    $seasons = $seriesExtended['data']['seasons'] ?? null;
    if (!is_array($seasons)) {
        return null;
    }

    foreach ($seasons as $season) {
        if (!is_array($season)) {
            continue;
        }
        if (($season['type']['type'] ?? null) !== 'official') {
            continue;
        }
        if (!isset($season['number']) || (int) $season['number'] !== $seasonNumber) {
            continue;
        }
        $id = $season['id'] ?? null;
        if (is_numeric($id)) {
            return (int) $id;
        }
    }

    return null;
}
