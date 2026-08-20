<?php
// Merge, de-duplicate, rank and cap.
//
// The client renders this order verbatim, so it has to be deliberate and it has
// to be stable: the same request twice must produce the same list in the same
// order, or a user's poster picker reshuffles under them between searches.
//
// Poster objects pass through whole. Nothing here enumerates their fields, so a
// source-specific optional field — `page`, the attribution link CC BY-SA artwork
// must carry — survives de-duplication, ranking and the limit without this file
// knowing about it. Keep it that way: rebuilding posters from a fixed field list
// here would silently drop the attribution a licence depends on.

if (!defined('MARQUEE_API_V2')) {
    http_response_code(404);
    exit;
}

/** Keep the first occurrence of each exact URL. */
function marqueeDeduplicatePosters(array $posters): array
{
    $seen = [];
    $unique = [];

    foreach ($posters as $poster) {
        if (!isset($poster['url']) || !is_string($poster['url']) || $poster['url'] === '') {
            continue;
        }
        if (isset($seen[$poster['url']])) {
            continue;
        }
        $seen[$poster['url']] = true;
        $unique[] = $poster;
    }

    return $unique;
}

/**
 * Best-first, deterministically.
 *
 * English artwork first, then language-neutral, then everything else; within a
 * tier the source's own rating, then resolution, then the URL as a stable
 * tie-break. A poster whose source supplies no rating sorts below rated ones
 * rather than tying with a genuine zero.
 */
function marqueeRankPosters(array $posters): array
{
    $languageRank = static function (array $poster): int {
        if (!isset($poster['language'])) {
            return 1;
        }
        return $poster['language'] === 'en' ? 0 : 2;
    };

    usort($posters, static function (array $a, array $b) use ($languageRank): int {
        $byLanguage = $languageRank($a) <=> $languageRank($b);
        if ($byLanguage !== 0) {
            return $byLanguage;
        }

        $scoreA = $a['score'] ?? -1;
        $scoreB = $b['score'] ?? -1;
        if ($scoreA != $scoreB) {
            return $scoreB <=> $scoreA;
        }

        $areaA = (int) ($a['width'] ?? 0) * (int) ($a['height'] ?? 0);
        $areaB = (int) ($b['width'] ?? 0) * (int) ($b['height'] ?? 0);
        if ($areaA !== $areaB) {
            return $areaB <=> $areaA;
        }

        return strcmp($a['url'], $b['url']);
    });

    return $posters;
}

/**
 * Assemble the final list.
 *
 * `total` is the count after de-duplication and before the limit, so a client can
 * tell "there are more, ask for a higher limit" from "that is all there is".
 */
function marqueeAssemblePosters(array $posters, int $limit): array
{
    $unique = marqueeRankPosters(marqueeDeduplicatePosters($posters));

    return [
        'posters' => array_slice($unique, 0, $limit),
        'total' => count($unique),
    ];
}
