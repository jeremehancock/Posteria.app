<?php
// Request parsing and validation.
//
// `type` has no default and no combined mode: the caller always says what kind of
// work it is looking for. The season number comes only from the `season`
// parameter and is never inferred from the text of `q` — inference misfires on
// ordinary titles ("Stranger Things 4" is a show, not a season).
//
// season=0 is Specials and is a real value throughout. Every presence check here
// and downstream is `!== null`, never truthiness.

if (!defined('MARQUEE_API_V1')) {
    http_response_code(404);
    exit;
}

function marqueeInvalidRequest(string $message): void
{
    marqueeSendFailure('invalid_request', $message);
}

/**
 * @return array{
 *   q: string, type: string, season: ?int, year: ?int,
 *   limit: int, sources: string[], debug: bool
 * }
 */
function marqueeParseRequest(array $query): array
{
    $q = isset($query['q']) && is_string($query['q']) ? trim($query['q']) : '';
    if ($q === '') {
        marqueeInvalidRequest('Parameter "q" is required and must be a non-empty title.');
    }

    $type = isset($query['type']) && is_string($query['type']) ? strtolower(trim($query['type'])) : '';
    if ($type === '') {
        marqueeInvalidRequest('Parameter "type" is required. Valid values: ' . implode(', ', VALID_TYPES) . '.');
    }
    if (!in_array($type, VALID_TYPES, true)) {
        marqueeInvalidRequest('Parameter "type" must be one of: ' . implode(', ', VALID_TYPES) . '.');
    }

    $season = null;
    $rawSeason = $query['season'] ?? null;
    if (is_string($rawSeason)) {
        $rawSeason = trim($rawSeason);
    }
    if ($rawSeason !== null && $rawSeason !== '') {
        if (!is_string($rawSeason) || !ctype_digit($rawSeason)) {
            marqueeInvalidRequest('Parameter "season" must be an integer of 0 or greater. 0 means Specials.');
        }
        $season = (int) $rawSeason;
    }
    if ($type === 'season' && $season === null) {
        marqueeInvalidRequest('Parameter "season" is required when type=season. 0 means Specials.');
    }
    if ($type !== 'season') {
        $season = null;
    }

    $year = null;
    $rawYear = $query['year'] ?? null;
    if (is_string($rawYear)) {
        $rawYear = trim($rawYear);
    }
    if ($rawYear !== null && $rawYear !== '') {
        if (!is_string($rawYear) || !preg_match('/^\d{4}$/', $rawYear)) {
            marqueeInvalidRequest('Parameter "year" must be a four-digit year.');
        }
        $year = (int) $rawYear;
    }

    $limit = LIMIT_DEFAULT;
    $rawLimit = $query['limit'] ?? null;
    if (is_string($rawLimit)) {
        $rawLimit = trim($rawLimit);
    }
    if ($rawLimit !== null && $rawLimit !== '') {
        if (!is_string($rawLimit) || !ctype_digit($rawLimit) || (int) $rawLimit < 1) {
            marqueeInvalidRequest('Parameter "limit" must be an integer of 1 or greater.');
        }
        // Clamped rather than rejected: a caller asking for more than we cap at
        // wants as many as possible, not an error.
        $limit = min((int) $rawLimit, LIMIT_MAX);
    }

    $sources = VALID_SOURCE_TOKENS;
    $rawSources = $query['sources'] ?? null;
    if (is_string($rawSources) && trim($rawSources) !== '') {
        $requested = array_filter(array_map(
            static fn($token) => strtolower(trim($token)),
            explode(',', $rawSources)
        ), static fn($token) => $token !== '');

        // Unrecognised tokens are ignored rather than rejected, so a client that
        // knows about a source this deployment does not have still works.
        $recognised = array_values(array_intersect(VALID_SOURCE_TOKENS, $requested));

        if ($recognised === []) {
            marqueeInvalidRequest('Parameter "sources" named no known source. Valid tokens: ' . implode(', ', VALID_SOURCE_TOKENS) . '.');
        }
        $sources = $recognised;
    }

    $debug = isset($query['debug']) && is_string($query['debug']) && strtolower(trim($query['debug'])) === 'true';

    return [
        'q' => $q,
        'type' => $type,
        'season' => $season,
        'year' => $year,
        'limit' => $limit,
        'sources' => $sources,
        'debug' => $debug,
    ];
}
