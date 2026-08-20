<?php
// Resolution: narrow a search to exactly one work before any artwork is gathered.
//
// This is the whole point of the endpoint. Handing a search-results page straight
// to the poster gatherer is what makes "The Matrix" return artwork for Reloaded,
// Revolutions, Resurrections and a documentary about the making of it. Resolution
// happens first, and only the winner's artwork is ever fetched.

if (!defined('MARQUEE_API_V2')) {
    http_response_code(404);
    exit;
}

/**
 * Normalise a title for comparison: lowercase, no diacritics, no punctuation,
 * single spaces, no leading article.
 */
function marqueeNormaliseTitle(string $title): string
{
    $value = trim($title);

    // Strip diacritics where the platform can, so "Amelie" matches "Amélie".
    if (function_exists('iconv')) {
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($transliterated) && $transliterated !== '') {
            $value = $transliterated;
        }
    }

    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    $value = preg_replace('/^(the|a|an)\s+/', '', $value) ?? $value;

    return trim($value);
}

/**
 * Normalise for comparison against a candidate of a given type.
 *
 * TMDB names every collection record "<Franchise> Collection"; a media server
 * names the same collection "<Franchise>". Making that token invisible on both
 * sides lets the exact-title branch fire instead of the prefix branch, which is
 * what keeps the score floor and the prefix weight where they are: sequels are
 * prefix matches too and score in the same band as a suffixed collection, so no
 * floor value admits one and excludes the other.
 *
 * Scoped deliberately:
 * - collections only, because "The Collection" (2012) is a legitimate film title
 * - trailing token only, so "The Criterion Collection Presents..." is not
 *   mangled mid-string
 */
function marqueeNormaliseTitleForType(string $title, string $type): string
{
    $normalised = marqueeNormaliseTitle($title);

    if ($type !== 'collection') {
        return $normalised;
    }

    $stripped = preg_replace('/\s+collection$/', '', $normalised) ?? $normalised;

    // A collection actually named "Collection" would otherwise normalise to an
    // empty string, which compares against everything.
    return $stripped === '' ? $normalised : $stripped;
}

/** Pull the comparable fields out of a TMDB search hit, whatever the type. */
function marqueeCandidateFacts(array $result, string $type): array
{
    if ($type === 'movie') {
        $titles = [$result['title'] ?? null, $result['original_title'] ?? null];
        $date = $result['release_date'] ?? null;
    } elseif ($type === 'collection') {
        $titles = [$result['name'] ?? null, $result['original_name'] ?? null];
        $date = null;
    } else {
        $titles = [$result['name'] ?? null, $result['original_name'] ?? null];
        $date = $result['first_air_date'] ?? null;
    }

    $titles = array_values(array_filter($titles, static fn($t) => is_string($t) && $t !== ''));

    $year = null;
    if (is_string($date) && preg_match('/^(\d{4})/', $date, $m)) {
        $year = (int) $m[1];
    }

    return [
        'tmdb_id' => isset($result['id']) ? (int) $result['id'] : null,
        'title' => $titles[0] ?? '',
        'titles' => $titles,
        'year' => $year,
        'popularity' => isset($result['popularity']) && is_numeric($result['popularity'])
            ? (float) $result['popularity']
            : 0.0,
    ];
}

/**
 * Score one candidate against the query.
 *
 * Weights, in decreasing order: an exact normalised-title match dominates; the
 * year hint adjusts; a prefix or whole-word match counts for less; popularity is
 * only ever a tie-break. An exact title match scores far enough above the floor
 * that a wrong year hint cannot push it below — the year disambiguates between
 * candidates, it never disqualifies one.
 *
 * `$normalisedQuery` must already have been normalised for `$type` by
 * marqueeNormaliseTitleForType(), so that both sides of every comparison here
 * have had the same treatment applied.
 */
function marqueeScoreCandidate(array $facts, string $normalisedQuery, ?int $year, string $type): float
{
    $score = 0.0;

    $best = 0.0;
    foreach ($facts['titles'] as $title) {
        $normalised = marqueeNormaliseTitleForType($title, $type);
        if ($normalised === '') {
            continue;
        }

        if ($normalised === $normalisedQuery) {
            $best = max($best, 60.0);
            continue;
        }
        if (str_starts_with($normalised, $normalisedQuery . ' ')) {
            $best = max($best, 25.0);
            continue;
        }
        if (str_starts_with($normalisedQuery, $normalised . ' ')) {
            $best = max($best, 20.0);
            continue;
        }
        if (str_contains(' ' . $normalised . ' ', ' ' . $normalisedQuery . ' ')) {
            $best = max($best, 15.0);
        }
    }
    $score += $best;

    if ($year !== null && $facts['year'] !== null) {
        $gap = abs($facts['year'] - $year);
        if ($gap === 0) {
            $score += 20.0;
        } elseif ($gap === 1) {
            // Release-year metadata disagrees across sources by a year routinely.
            $score += 10.0;
        } else {
            // Deliberately smaller than the gap between an exact title match and a
            // prefix one: a title that matches exactly with a wrong year is bad
            // metadata for the right work, not a different work.
            $score -= 10.0;
        }
    }

    // Bounded so it can separate equals without ever outweighing a title match.
    $score += min($facts['popularity'], 100.0) / 100.0 * 5.0;

    return $score;
}

/** The reportable summary of one scored candidate. */
function marqueeCandidateSummary(array $candidate): array
{
    return [
        'tmdb_id' => $candidate['tmdb_id'],
        'title' => $candidate['title'],
        'year' => $candidate['year'],
        'score' => $candidate['score'],
    ];
}

/**
 * Pick one work, or nothing.
 *
 * `$diagnostics` reports how the decision was reached whether or not one was
 * made, because a rejection is the case worth explaining: an empty `candidates`
 * means the provider had no record of the query, while a populated one whose top
 * score is under `score_floor` means the provider found the work and the floor
 * turned it down. It is an out-parameter rather than part of the return value so
 * that the "one work or nothing" contract, and every caller's null check, stand.
 *
 * @param array|null $diagnostics out: query_normalised, score_floor, candidates
 * @return array{winner: array, score: float, rejected: array}|null
 */
function marqueeResolveWork(
    array $searchPayload,
    string $type,
    string $query,
    ?int $year,
    ?array &$diagnostics = null
): ?array {
    $normalisedQuery = marqueeNormaliseTitleForType($query, $type);

    $diagnostics = [
        'query_normalised' => $normalisedQuery,
        'score_floor' => RESOLVE_SCORE_FLOOR,
        'candidates' => [],
    ];

    $results = is_array($searchPayload) && isset($searchPayload['results']) && is_array($searchPayload['results'])
        ? $searchPayload['results']
        : [];

    if ($results === []) {
        return null;
    }

    $scored = [];

    foreach ($results as $result) {
        if (!is_array($result)) {
            continue;
        }
        $facts = marqueeCandidateFacts($result, $type);
        if ($facts['tmdb_id'] === null || $facts['titles'] === []) {
            continue;
        }
        $facts['score'] = round(marqueeScoreCandidate($facts, $normalisedQuery, $year, $type), 3);
        $scored[] = $facts;
    }

    if ($scored === []) {
        return null;
    }

    usort($scored, static function (array $a, array $b): int {
        return $b['score'] <=> $a['score']
            ?: $b['popularity'] <=> $a['popularity']
            ?: $a['tmdb_id'] <=> $b['tmdb_id'];
    });

    // Capped like the winner's `rejected` list below. Top-first, so the
    // near-miss that explains a rejection is always the first entry.
    $diagnostics['candidates'] = array_map('marqueeCandidateSummary', array_slice($scored, 0, 10));

    $winner = $scored[0];
    if ($winner['score'] < RESOLVE_SCORE_FLOOR) {
        return null;
    }

    $rejected = array_map('marqueeCandidateSummary', array_slice($scored, 1, 10));

    return ['winner' => $winner, 'score' => $winner['score'], 'rejected' => $rejected];
}

/**
 * Build the `match` object from the resolved work's details.
 *
 * Identifiers that could not be resolved are null rather than absent, so the
 * client can always read them. tvdb_id comes from TMDB's external_ids — it is not
 * a TheTVDB credential, and fanart.tv's TV endpoint depends on it.
 */
function marqueeBuildMatch(string $type, array $candidate, ?array $details): array
{
    $match = [
        'title' => $candidate['title'],
        'year' => $candidate['year'],
        'type' => $type,
        'tmdb_id' => $candidate['tmdb_id'],
        'imdb_id' => null,
        'tvdb_id' => null,
    ];

    if (!is_array($details)) {
        return $match;
    }

    if ($type === 'movie') {
        if (!empty($details['title']) && is_string($details['title'])) {
            $match['title'] = $details['title'];
        }
        if (!empty($details['release_date']) && preg_match('/^(\d{4})/', (string) $details['release_date'], $m)) {
            $match['year'] = (int) $m[1];
        }
        if (!empty($details['imdb_id']) && is_string($details['imdb_id'])) {
            $match['imdb_id'] = $details['imdb_id'];
        }
    } elseif ($type === 'collection') {
        if (!empty($details['name']) && is_string($details['name'])) {
            $match['title'] = $details['name'];
        }
    } else {
        if (!empty($details['name']) && is_string($details['name'])) {
            $match['title'] = $details['name'];
        }
        if (!empty($details['first_air_date']) && preg_match('/^(\d{4})/', (string) $details['first_air_date'], $m)) {
            $match['year'] = (int) $m[1];
        }
    }

    $externalIds = $details['external_ids'] ?? null;
    if (is_array($externalIds)) {
        if (!empty($externalIds['imdb_id']) && is_string($externalIds['imdb_id'])) {
            $match['imdb_id'] = $externalIds['imdb_id'];
        }
        if (!empty($externalIds['tvdb_id']) && is_numeric($externalIds['tvdb_id'])) {
            $match['tvdb_id'] = (int) $externalIds['tvdb_id'];
        }
    }

    return $match;
}

/** The `season` object carried inside `match` on a season request. */
function marqueeBuildSeasonIdentity(int $seasonNumber, ?array $seasonDetails): array
{
    $identity = [
        'number' => $seasonNumber,
        'name' => $seasonNumber === 0 ? 'Specials' : 'Season ' . $seasonNumber,
        'episode_count' => null,
        'air_date' => null,
    ];

    if (!is_array($seasonDetails)) {
        return $identity;
    }

    if (!empty($seasonDetails['name']) && is_string($seasonDetails['name'])) {
        $identity['name'] = $seasonDetails['name'];
    }
    if (isset($seasonDetails['episodes']) && is_array($seasonDetails['episodes'])) {
        $identity['episode_count'] = count($seasonDetails['episodes']);
    }
    if (!empty($seasonDetails['air_date']) && is_string($seasonDetails['air_date'])) {
        $identity['air_date'] = $seasonDetails['air_date'];
    }

    return $identity;
}
