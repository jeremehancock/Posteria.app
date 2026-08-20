<?php
// TVmaze client.
//
// Television only, and free: TVmaze needs no credential, so unlike every other
// source here it cannot be `skipped` for want of one. It is the only source that
// works on a deployment holding nothing but a TMDB key.
//
// Records are located by remote id — the TVDB id TMDB already hands us in
// external_ids, falling back to the IMDb id — never by title. TVmaze publishes
// /search/shows?q=, and using it would let TVmaze answer for a show the query did
// not name; that is the wrong-work failure this whole endpoint exists to prevent,
// and a free search endpoint is not a reason to reintroduce it.
//
// TVmaze data is licensed CC BY-SA. Attribution is a licence term, not a courtesy,
// so every poster carries `page`: the show's URL for show artwork, the season's own
// URL for season artwork. The response is the only place a client can learn that
// link, so omitting it would make a compliant client impossible.

if (!defined('MARQUEE_API_V2')) {
    http_response_code(404);
    exit;
}

/**
 * Build the lookup URL for a work, or null when no identifier is available.
 *
 * The TVDB id is tried first because it is already resolved for fanart.tv's
 * television endpoint, so it costs nothing extra. IMDb is the fallback for a show
 * TMDB knows an IMDb id for but no TVDB id. With neither, there is nothing to ask
 * and the caller reports `no_data`.
 */
function marqueeTvmazeLookupUrl(?int $tvdbId, ?string $imdbId): ?string
{
    if ($tvdbId !== null) {
        return TVMAZE_BASE_URL . '/lookup/shows?thetvdb=' . $tvdbId;
    }

    if ($imdbId !== null && $imdbId !== '') {
        return TVMAZE_BASE_URL . '/lookup/shows?imdb=' . urlencode($imdbId);
    }

    return null;
}

/**
 * Read the TVmaze show id out of a lookup response.
 *
 * The lookup answers 301 to /shows/:id, which the HTTP client follows, so the body
 * here is the show record itself rather than a redirect envelope.
 */
function marqueeTvmazeShowId(?array $payload): ?int
{
    $id = $payload['id'] ?? null;
    return is_numeric($id) ? (int) $id : null;
}

/** The show's own page, used as the attribution link for show artwork. */
function marqueeTvmazeShowPage(?array $payload): ?string
{
    $url = $payload['url'] ?? null;
    return is_string($url) && $url !== '' ? $url : null;
}

/**
 * Map /shows/:id/images to poster objects.
 *
 * Only entries typed `poster` are taken. TVmaze also carries `banner`,
 * `background`, `typography` and untyped legacy rows; none of those is a poster and
 * returning them would put billboards in a poster picker.
 *
 * `main` is deliberately not carried. TVmaze flags exactly one image per show as
 * the primary, but that is a designation rather than a rating and there is no
 * honest scale to express it on — the same reasoning that omits fanart's like count
 * and TheTVDB's hundred-thousand-scale score. Without a `score` these rank on pixel
 * area, which the dimension spread (2–7 distinct sizes per show) makes meaningful.
 *
 * No `language` either: TVmaze does not record one per image, and guessing English
 * from the site's own language would put unlabelled artwork above genuinely English
 * artwork from sources that do label it.
 */
function marqueeTvmazePosters($images, ?string $page): array
{
    if (!is_array($images)) {
        return [];
    }

    $posters = [];
    foreach ($images as $image) {
        if (!is_array($image) || ($image['type'] ?? null) !== TVMAZE_IMAGE_TYPE_POSTER) {
            continue;
        }

        $original = $image['resolutions']['original'] ?? null;
        if (!is_array($original) || empty($original['url']) || !is_string($original['url'])) {
            continue;
        }

        $poster = [
            'url' => $original['url'],
            'source' => SOURCE_LABELS['tvmaze'],
        ];

        $medium = $image['resolutions']['medium'] ?? null;
        if (is_array($medium) && !empty($medium['url']) && is_string($medium['url'])) {
            $poster['thumb'] = $medium['url'];
        }

        // Dimensions describe the original, which is what `url` points at.
        if (isset($original['width']) && is_numeric($original['width'])) {
            $poster['width'] = (int) $original['width'];
        }
        if (isset($original['height']) && is_numeric($original['height'])) {
            $poster['height'] = (int) $original['height'];
        }

        if ($page !== null) {
            $poster['page'] = $page;
            // CC BY-SA: rendering this link is a licence term, not a courtesy. Every
            // other source carries `page` as provenance only, so the marker is what
            // lets a client tell the obligation from the convenience.
            $poster['attribution_required'] = true;
        }

        $posters[] = $poster;
    }

    return $posters;
}

/**
 * Map /shows/:id/seasons to the requested season's poster.
 *
 * TVmaze holds at most one image per season, so this returns a list of zero or one
 * rather than a set. That one image is frequently artwork no other source carries,
 * which is why a season is worth asking about at all.
 *
 * Season 0 is Specials and is matched as a real value, never as an absent one.
 *
 * The attribution link is the season's own page, not the show's: TVmaze publishes
 * both and they differ, and the credit belongs to the page showing the artwork.
 *
 * No dimensions — TVmaze publishes none for a season image. They are omitted rather
 * than inferred from the show's posters, which are different images entirely.
 */
function marqueeTvmazeSeasonPosters($seasons, int $seasonNumber): array
{
    if (!is_array($seasons)) {
        return [];
    }

    foreach ($seasons as $season) {
        if (!is_array($season)) {
            continue;
        }
        if (!isset($season['number']) || !is_numeric($season['number'])) {
            continue;
        }
        if ((int) $season['number'] !== $seasonNumber) {
            continue;
        }

        $image = $season['image'] ?? null;
        if (!is_array($image) || empty($image['original']) || !is_string($image['original'])) {
            // The season exists but carries no artwork. Show-level artwork is not
            // substituted: a season request returns season artwork or nothing.
            return [];
        }

        $poster = [
            'url' => $image['original'],
            'source' => SOURCE_LABELS['tvmaze'],
        ];

        if (!empty($image['medium']) && is_string($image['medium'])) {
            $poster['thumb'] = $image['medium'];
        }

        if (!empty($season['url']) && is_string($season['url'])) {
            $poster['page'] = $season['url'];
            $poster['attribution_required'] = true;
        }

        return [$poster];
    }

    return [];
}
