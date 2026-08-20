<?php
// Fixture harness for resolution, de-duplication, ranking and storage.
//
// Uses provider-shaped fixtures rather than live calls, so the accuracy fix is
// verifiable without credentials and without spending provider quota.
//
//   php marquee/api/v1/tests/resolve_test.php
//
// CLI only: the deployment serves this tree over HTTP, and a test runner should
// not be reachable as a URL.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('MARQUEE_API_V2', true);
$lib = __DIR__ . '/../lib/';
require_once $lib . 'config.php';
require_once $lib . 'resolve.php';
require_once $lib . 'tmdb.php';
require_once $lib . 'posters.php';

$pass = 0;
$fail = 0;

function check(string $label, $actual, $expected): void
{
    global $pass, $fail;
    $ok = $actual === $expected;
    $ok ? $pass++ : $fail++;
    printf("%-58s %s%s\n", $label, $ok ? 'PASS' : 'FAIL',
        $ok ? '' : "  (got " . var_export($actual, true) . ", want " . var_export($expected, true) . ")");
}

function movie(int $id, string $title, ?string $date, float $pop): array
{
    return ['id' => $id, 'title' => $title, 'original_title' => $title, 'release_date' => $date, 'popularity' => $pop];
}
function show(int $id, string $name, ?string $date, float $pop): array
{
    return ['id' => $id, 'name' => $name, 'original_name' => $name, 'first_air_date' => $date, 'popularity' => $pop];
}
function coll(int $id, string $name): array
{
    return ['id' => $id, 'name' => $name, 'popularity' => 1.0];
}

// --- The Matrix: the query behind the 683-result problem -------------------
$matrix = ['results' => [
    movie(604, 'The Matrix Reloaded', '2003-05-15', 45.0),
    movie(603, 'The Matrix', '1999-03-30', 62.0),
    movie(605, 'The Matrix Revolutions', '2003-11-05', 38.0),
    movie(624860, 'The Matrix Resurrections', '2021-12-16', 41.0),
    movie(14612, 'The Matrix Revisited', '2001-11-13', 5.0),
    movie(128280, 'Making "The Matrix"', '1999-09-21', 3.0),
    movie(999001, 'Sex and the Matrix', '2000-01-01', 1.0),
    movie(999002, 'A Glitch in the Matrix', '2021-02-05', 9.0),
    movie(999003, 'The Living Matrix', '2009-01-01', 2.0),
    movie(999004, 'Exit the matrix', '2026-01-01', 0.5),
    // Popularity deliberately above the 1999 film's. If the collection-suffix
    // strip ever leaks into movie scoring, this ties at 60 and wins the
    // tie-break, so the guard below fails loudly instead of passing by luck.
    movie(999005, 'The Matrix Collection', '2018-01-01', 90.0),
]];

$r = marqueeResolveWork($matrix, 'movie', 'The Matrix', 1999);
check('Matrix + year 1999 resolves to 603', $r['winner']['tmdb_id'], 603);

$r = marqueeResolveWork($matrix, 'movie', 'The Matrix', null);
check('Matrix, no year, still resolves to 603', $r['winner']['tmdb_id'], 603);

// Exact title with wrong year is bad metadata for the right work.
$r = marqueeResolveWork($matrix, 'movie', 'The Matrix', 2003);
check('Matrix + wrong year 2003 still resolves to 603', $r['winner']['tmdb_id'], 603);

$r = marqueeResolveWork($matrix, 'movie', 'The Matrix Reloaded', null);
check('Explicit Reloaded resolves to 604', $r['winner']['tmdb_id'], 604);

check('Rejected list is populated for debug', count($r['rejected']) > 0, true);

// --- Breaking Bad ---------------------------------------------------------
$bb = ['results' => [
    show(1396, 'Breaking Bad', '2008-01-20', 210.0),
    show(999010, 'The Bad Guys: The Series', '2022-01-01', 30.0),
    show(999011, 'Breaking the Deal with My Hockey Bad Boy', '2024-01-01', 6.0),
    show(999012, 'Breaking Bad: Original Minisodes', '2009-01-01', 4.0),
]];
$r = marqueeResolveWork($bb, 'show', 'Breaking Bad', null);
check('Breaking Bad resolves to 1396', $r['winner']['tmdb_id'], 1396);

// --- Collections ----------------------------------------------------------
$sw = ['results' => [
    coll(999020, 'LEGO Star Wars Collection'),
    coll(10, 'Star Wars Collection'),
    coll(999021, 'Robot Chicken - Star Wars Collection'),
    coll(999022, 'Star Wars: The Ewok Adventures Collection'),
    coll(999023, 'The Man Who Saved the World Collection'),
]];
$r = marqueeResolveWork($sw, 'collection', 'Star Wars Collection', null);
check('Star Wars Collection resolves to 10', $r['winner']['tmdb_id'], 10);

// Plex names a collection "Star Wars"; TMDB names every collection record
// "<Franchise> Collection". This is the vocabulary the client actually sends,
// and the only vocabulary the original fixtures never covered.
$r = marqueeResolveWork($sw, 'collection', 'Star Wars', null);
check('Star Wars (no suffix) resolves to 10', $r['winner']['tmdb_id'] ?? null, 10);

$r = marqueeResolveWork($sw, 'collection', 'star wars collection', null);
check('Lowercase suffixed form resolves to 10', $r['winner']['tmdb_id'] ?? null, 10);

// The year hint is inert for collections: marqueeCandidateFacts() carries no
// date for the type, so this must resolve on title alone.
$r = marqueeResolveWork($sw, 'collection', 'Star Wars', 1977);
check('Star Wars + year still resolves to 10', $r['winner']['tmdb_id'] ?? null, 10);

// The suffix is stripped from a trailing token only, so a franchise-prefixed
// collection stays distinct rather than collapsing onto the bare franchise.
$r = marqueeResolveWork($sw, 'collection', 'LEGO Star Wars', null);
check('LEGO Star Wars resolves to its own collection', $r['winner']['tmdb_id'] ?? null, 999020);

// --- Movie scoring must not loosen ----------------------------------------
// The guard: a movie query must never be answered with a collection record or
// a sequel, whatever the suffix handling does for collections.
$r = marqueeResolveWork($matrix, 'movie', 'The Matrix', null);
check('Matrix movie is not the collection record', $r['winner']['tmdb_id'], 603);

$collection = ['results' => [
    movie(105906, 'The Collection', '2012-11-30', 14.0),
    movie(999040, 'The Collector', '2009-07-31', 11.0),
]];
$r = marqueeResolveWork($collection, 'movie', 'The Collection', null);
check('A movie titled The Collection resolves', $r['winner']['tmdb_id'] ?? null, 105906);

// --- No match -------------------------------------------------------------
check('Empty results is a no-match', marqueeResolveWork(['results' => []], 'movie', 'Zzzznotarealtitle', null), null);

$junk = ['results' => [
    movie(999030, 'Something Entirely Different', '2010-01-01', 12.0),
    movie(999031, 'Another Unrelated Film', '2011-01-01', 8.0),
]];
check('Unrelated results fall below the floor', marqueeResolveWork($junk, 'movie', 'Zzzznotarealtitle', null), null);

// A custom Plex collection has no upstream record at all. Still a no-match
// after the suffix fix, and correctly so — there is nothing to resolve to.
check(
    'A custom collection with no record is a no-match',
    marqueeResolveWork($sw, 'collection', 'Dad\'s Favourites', null),
    null
);

// Stripping must not reduce a query to nothing and match everything.
check(
    'A bare "Collection" query matches nothing',
    marqueeResolveWork($sw, 'collection', 'Collection', null),
    null
);

// --- Normalisation --------------------------------------------------------
check('Leading article dropped', marqueeNormaliseTitle('The Matrix'), 'matrix');
check('Punctuation and case normalised', marqueeNormaliseTitle('WALL·E: The  Movie!'), 'wall e the movie');
check('Diacritics folded', marqueeNormaliseTitle('Amélie'), 'amelie');

// Type-aware normalisation: the collection suffix, and only that.
check(
    'Collection suffix stripped for collections',
    marqueeNormaliseTitleForType('Star Wars Collection', 'collection'),
    'star wars'
);
check(
    'Unsuffixed collection is unchanged',
    marqueeNormaliseTitleForType('Star Wars', 'collection'),
    'star wars'
);
check(
    'Collection suffix retained for movies',
    marqueeNormaliseTitleForType('The Matrix Collection', 'movie'),
    'matrix collection'
);
check(
    'Collection suffix retained for shows',
    marqueeNormaliseTitleForType('The Office Collection', 'show'),
    'office collection'
);
check(
    'Mid-string Collection is retained',
    marqueeNormaliseTitleForType('The Criterion Collection Presents', 'collection'),
    'criterion collection presents'
);
check(
    'A bare Collection does not strip to empty',
    marqueeNormaliseTitleForType('Collection', 'collection'),
    'collection'
);
check(
    'Trailing whitespace and case do not defeat the strip',
    marqueeNormaliseTitleForType('  ALIEN   collection  ', 'collection'),
    'alien'
);
$r = marqueeResolveWork($matrix, 'movie', 'the   MATRIX', null);
check('Sloppy spelling resolves identically', $r['winner']['tmdb_id'], 603);

// --- Rejection diagnostics ------------------------------------------------
// The 404 is the case where the scores matter most: these two shapes are the
// difference between "nothing upstream" and "the floor turned it down".
$d = null;
check('Below-floor rejection still returns null', marqueeResolveWork($junk, 'movie', 'Zzzznotarealtitle', null, $d), null);
check('Rejection reports the normalised query', $d['query_normalised'], 'zzzznotarealtitle');
check('Rejection reports the floor', $d['score_floor'], RESOLVE_SCORE_FLOOR);
check('Rejection lists the candidates it scored', count($d['candidates']), 2);
check('Top rejected candidate scored under the floor', $d['candidates'][0]['score'] < RESOLVE_SCORE_FLOOR, true);

$d = null;
check('Empty results is still a no-match', marqueeResolveWork(['results' => []], 'movie', 'Zzzznotarealtitle', null, $d), null);
check('No upstream record means an empty candidate list', $d['candidates'], []);
check('Empty results still reports the floor', $d['score_floor'], RESOLVE_SCORE_FLOOR);

$d = null;
$r = marqueeResolveWork($sw, 'collection', 'Star Wars', null, $d);
check('Diagnostics normalise the query for the type', $d['query_normalised'], 'star wars');
check('A resolved query reports its candidates too', $d['candidates'][0]['tmdb_id'], 10);
check('Resolution is unaffected by collecting diagnostics', $r['winner']['tmdb_id'], 10);

// --- Season identity ------------------------------------------------------
$s = marqueeBuildSeasonIdentity(0, ['name' => 'Specials', 'air_date' => '2009-02-17', 'episodes' => [[], [], []]]);
check('Specials keeps number 0', $s['number'], 0);
check('Specials episode count', $s['episode_count'], 3);
$s = marqueeBuildSeasonIdentity(2, null);
check('Season falls back to a sane name', $s['name'], 'Season 2');

// --- Poster mapping, de-duplication, ranking ------------------------------
$images = ['posters' => [
    ['file_path' => '/a.jpg', 'width' => 2000, 'height' => 3000, 'iso_639_1' => 'en', 'vote_average' => 8.2, 'vote_count' => 12],
    ['file_path' => '/b.jpg', 'width' => 1000, 'height' => 1500, 'iso_639_1' => null, 'vote_average' => 0, 'vote_count' => 0],
    ['file_path' => '/a.jpg', 'width' => 2000, 'height' => 3000, 'iso_639_1' => 'en', 'vote_average' => 8.2, 'vote_count' => 12],
]];
$mapped = marqueeTmdbPosters($images);
check('TMDB images mapped', count($mapped), 3);
check('url is original size', $mapped[0]['url'], 'https://image.tmdb.org/t/p/original/a.jpg');
check('thumb is w342', $mapped[0]['thumb'], 'https://image.tmdb.org/t/p/w342/a.jpg');
check('score carried when rated', $mapped[0]['score'], 8.2);
check('score omitted when unrated', array_key_exists('score', $mapped[1]), false);
check('language omitted when null', array_key_exists('language', $mapped[1]), false);

$mixed = array_merge($mapped, [
    ['url' => 'https://assets.fanart.tv/x.jpg', 'source' => 'fanart.tv', 'language' => 'en'],
    ['url' => 'https://assets.fanart.tv/y.jpg', 'source' => 'fanart.tv'],
    ['url' => 'https://assets.fanart.tv/x.jpg', 'source' => 'fanart.tv', 'language' => 'en'],
]);
$assembled = marqueeAssemblePosters($mixed, 200);
$urls = array_column($assembled['posters'], 'url');
check('duplicates removed', count($urls), count(array_unique($urls)));
check('total is post-dedup', $assembled['total'], 4);
check('english art ranks first', $assembled['posters'][0]['source'], 'tmdb');
check('rated english outranks unrated english', $assembled['posters'][1]['source'], 'fanart.tv');

$capped = marqueeAssemblePosters($mixed, 2);
check('limit applied after ranking', count($capped['posters']), 2);
check('total ignores the limit', $capped['total'], 4);

// Stability: the same input twice orders identically.
$a = array_column(marqueeAssemblePosters($mixed, 200)['posters'], 'url');
shuffle($mixed);
$b = array_column(marqueeAssemblePosters($mixed, 200)['posters'], 'url');
check('ranking is order-independent and stable', $a, $b);

// --- fanart season filtering ---------------------------------------------
require_once $lib . 'sources.php';
$fanart = ['seasonposter' => [
    ['url' => 'https://f/s0.jpg', 'season' => '0', 'lang' => 'en'],
    ['url' => 'https://f/s2a.jpg', 'season' => '2', 'lang' => 'en'],
    ['url' => 'https://f/s2b.jpg', 'season' => '2', 'lang' => '00'],
    ['url' => 'https://f/s3.jpg', 'season' => '3', 'lang' => 'en'],
    ['url' => 'https://f/all.jpg', 'season' => 'all', 'lang' => 'en'],
], 'tvposter' => [
    ['url' => 'https://f/show.jpg', 'lang' => 'en'],
]];
check('season 2 filter', count(marqueeMapFanartPosters($fanart, 'season', 2)), 2);
check('season 0 filter (Specials)', count(marqueeMapFanartPosters($fanart, 'season', 0)), 1);
check('season query never takes tvposter', array_column(marqueeMapFanartPosters($fanart, 'season', 2), 'url'),
    ['https://f/s2a.jpg', 'https://f/s2b.jpg']);
check('show query never takes seasonposter', array_column(marqueeMapFanartPosters($fanart, 'show', null), 'url'),
    ['https://f/show.jpg']);
check('fanart attributed to fanart.tv', marqueeMapFanartPosters($fanart, 'show', null)[0]['source'], 'fanart.tv');
check('language-neutral 00 omitted', array_key_exists('language', marqueeMapFanartPosters($fanart, 'season', 2)[1]), false);

// --- Source set -----------------------------------------------------------
// Mediux is not a source here: its staging host is gone and the production host
// rejects this deployment's credential.
check('selectable sources', VALID_SOURCE_TOKENS, ['tmdb', 'fanart', 'tvdb', 'tvmaze']);
check('tvmaze label', SOURCE_LABELS['tvmaze'], 'tvmaze');
check('tvmaze needs no credential', array_key_exists('tvmaze', marqueeCredentials()), false);
check('no mediux label', array_key_exists('mediux', SOURCE_LABELS), false);
check('no mediux mapper', function_exists('marqueeMapMediuxPosters'), false);
check('mediux credential not read', array_key_exists('mediux', marqueeCredentials()), false);

// --- TheTVDB --------------------------------------------------------------
require_once $lib . 'tvdb.php';

// A remote-id lookup can return several records: the numeric TMDB movie id 603
// also matches an unrelated series. Filtering by record type is what stops the
// wrong work's artwork being attached.
$remote = ['data' => [
    ['series' => ['id' => 77013, 'name' => "Veronica's Closet"]],
    ['movie' => ['id' => 169, 'name' => 'The Matrix']],
]];
check('remote id picks the movie record', marqueeTvdbFindByRemoteId($remote, 'movie'), 169);
check('remote id picks the series record', marqueeTvdbFindByRemoteId($remote, 'series'), 77013);
check('remote id with no such type', marqueeTvdbFindByRemoteId(['data' => []], 'movie'), null);

check('639-3 mapped to 639-1', marqueeTvdbLanguage('eng'), 'en');
check('alternate 639-2/B code mapped', marqueeTvdbLanguage('ger'), 'de');
check('unknown code passed through', marqueeTvdbLanguage('xyz'), 'xyz');
check('absent language stays null', marqueeTvdbLanguage(null), null);

$artworks = [
    ['image' => 'https://a/p1.jpg', 'thumbnail' => 'https://a/p1_t.jpg', 'language' => 'eng',
     'type' => 14, 'score' => 100003, 'width' => 680, 'height' => 1000],
    ['image' => 'https://a/bg.jpg', 'type' => 15, 'score' => 100000],
    ['image' => 'https://a/p2.jpg', 'language' => 'rus', 'type' => 14, 'width' => 680, 'height' => 1000],
];
$mapped = marqueeTvdbPosters($artworks, 14);
check('only the wanted artwork type', count($mapped), 2);
check('tvdb attributed to thetvdb', $mapped[0]['source'], 'thetvdb');
check('tvdb thumbnail carried', $mapped[0]['thumb'], 'https://a/p1_t.jpg');
check('tvdb language normalised', $mapped[0]['language'], 'en');
// TheTVDB scores run in the hundred-thousands; carrying one across would rank
// every TheTVDB poster above every rated TMDB one regardless of quality.
check('tvdb score never carried', array_key_exists('score', $mapped[0]), false);
check('missing thumbnail omitted', array_key_exists('thumb', $mapped[1]), false);
check('tvdb garbage yields nothing', marqueeTvdbPosters(null, 14), []);

$seriesExtended = ['data' => ['seasons' => [
    ['id' => 30272, 'number' => 1, 'type' => ['type' => 'official']],
    ['id' => 40719, 'number' => 2, 'type' => ['type' => 'official']],
    ['id' => 999999, 'number' => 2, 'type' => ['type' => 'dvd']],
    ['id' => 439371, 'number' => 0, 'type' => ['type' => 'official']],
]]];
check('season id by number', marqueeTvdbSeasonId($seriesExtended, 2), 40719);
check('specials season id', marqueeTvdbSeasonId($seriesExtended, 0), 439371);
check('alternate orderings ignored', marqueeTvdbSeasonId($seriesExtended, 2) !== 999999, true);
check('absent season', marqueeTvdbSeasonId($seriesExtended, 9), null);

// --- TVmaze ---------------------------------------------------------------
require_once $lib . 'tvmaze.php';

// The TVDB id is preferred because it is already resolved for fanart.tv, so it
// costs nothing extra. IMDb is the fallback. With neither there is nothing to ask,
// and a title search is not an option — that is the wrong-work failure this
// endpoint exists to prevent.
check('tvdb id preferred', marqueeTvmazeLookupUrl(81189, 'tt0903747'),
    'https://api.tvmaze.com/lookup/shows?thetvdb=81189');
check('imdb id is the fallback', marqueeTvmazeLookupUrl(null, 'tt0903747'),
    'https://api.tvmaze.com/lookup/shows?imdb=tt0903747');
check('no identifier yields no lookup', marqueeTvmazeLookupUrl(null, null), null);
check('empty imdb treated as absent', marqueeTvmazeLookupUrl(null, ''), null);

// The lookup 301s to the show record, which the HTTP client follows, so the body
// is the show itself: its id addresses the artwork, its url is the attribution link.
$show = ['id' => 169, 'url' => 'https://www.tvmaze.com/shows/169/breaking-bad', 'name' => 'Breaking Bad'];
check('show id read from the lookup body', marqueeTvmazeShowId($show), 169);
check('show page read from the lookup body', marqueeTvmazeShowPage($show),
    'https://www.tvmaze.com/shows/169/breaking-bad');
check('missing show id', marqueeTvmazeShowId([]), null);
check('missing show page', marqueeTvmazeShowPage([]), null);

$images = [
    ['id' => 1, 'type' => 'poster', 'main' => true, 'resolutions' => [
        'original' => ['url' => 'https://tv/p1.jpg', 'width' => 680, 'height' => 1000],
        'medium' => ['url' => 'https://tv/p1_m.jpg', 'width' => 210, 'height' => 295],
    ]],
    ['id' => 2, 'type' => 'background', 'resolutions' => [
        'original' => ['url' => 'https://tv/bg.jpg', 'width' => 1920, 'height' => 1080],
    ]],
    ['id' => 3, 'type' => 'banner', 'resolutions' => ['original' => ['url' => 'https://tv/ban.jpg']]],
    ['id' => 4, 'type' => null, 'resolutions' => ['original' => ['url' => 'https://tv/legacy.jpg']]],
    ['id' => 5, 'type' => 'poster', 'resolutions' => [
        'original' => ['url' => 'https://tv/p2.jpg', 'width' => 1250, 'height' => 1800],
    ]],
];
$tvm = marqueeTvmazePosters($images, 'https://www.tvmaze.com/shows/169/breaking-bad');
check('only poster-typed images kept', count($tvm), 2);
check('backgrounds and banners dropped',
    array_column($tvm, 'url'), ['https://tv/p1.jpg', 'https://tv/p2.jpg']);
check('tvmaze attributed to tvmaze', $tvm[0]['source'], 'tvmaze');
check('medium rendition becomes thumb', $tvm[0]['thumb'], 'https://tv/p1_m.jpg');
check('dimensions describe the original', [$tvm[0]['width'], $tvm[0]['height']], [680, 1000]);
check('missing medium omits thumb', array_key_exists('thumb', $tvm[1]), false);
// CC BY-SA: the link back is a licence term, and the response is the only place a
// client can learn it.
check('every poster carries page', $tvm[0]['page'], 'https://www.tvmaze.com/shows/169/breaking-bad');
check('page on the second poster too', $tvm[1]['page'], 'https://www.tvmaze.com/shows/169/breaking-bad');
// `main` is a designation, not a rating. Carrying it as a score would put every
// tvmaze poster above every rated one — the fanart like-count reasoning again.
check('main never carried', array_key_exists('main', $tvm[0]), false);
check('tvmaze carries no score', array_key_exists('score', $tvm[0]), false);
check('tvmaze carries no language', array_key_exists('language', $tvm[0]), false);
check('tvmaze garbage yields nothing', marqueeTvmazePosters(null, 'https://p'), []);
check('no page when none known', array_key_exists('page', marqueeTvmazePosters($images, null)[0]), false);

// Seasons: at most one image each, and the attribution link is the season's own
// page rather than the show's.
$seasons = [
    ['id' => 753, 'number' => 1, 'url' => 'https://www.tvmaze.com/seasons/753/bb-season-1',
     'image' => ['original' => 'https://tv/s1.jpg', 'medium' => 'https://tv/s1_m.jpg']],
    ['id' => 754, 'number' => 2, 'url' => 'https://www.tvmaze.com/seasons/754/bb-season-2',
     'image' => ['original' => 'https://tv/s2.jpg', 'medium' => 'https://tv/s2_m.jpg']],
    ['id' => 755, 'number' => 3, 'url' => 'https://www.tvmaze.com/seasons/755/bb-season-3',
     'image' => null],
    ['id' => 752, 'number' => 0, 'url' => 'https://www.tvmaze.com/seasons/752/bb-specials',
     'image' => ['original' => 'https://tv/s0.jpg']],
];
$s2 = marqueeTvmazeSeasonPosters($seasons, 2);
check('season selected by number', count($s2), 1);
check('season poster url', $s2[0]['url'], 'https://tv/s2.jpg');
check('season thumb carried', $s2[0]['thumb'], 'https://tv/s2_m.jpg');
check('season page is the season\'s own', $s2[0]['page'], 'https://www.tvmaze.com/seasons/754/bb-season-2');
// TVmaze publishes no dimensions for a season image. Inferring them from the
// show's posters would describe a different image entirely.
check('season poster has no dimensions', array_key_exists('width', $s2[0]), false);
check('specials is a real season number', count(marqueeTvmazeSeasonPosters($seasons, 0)), 1);
// A season with no artwork returns nothing rather than falling back to the show's.
check('season without an image yields nothing', marqueeTvmazeSeasonPosters($seasons, 3), []);
check('absent season yields nothing', marqueeTvmazeSeasonPosters($seasons, 99), []);
check('season garbage yields nothing', marqueeTvmazeSeasonPosters(null, 1), []);

// Television only: a movie or collection is never asked.
check('tvmaze handles shows', marqueeSourceHandlesType('tvmaze', 'show'), true);
check('tvmaze handles seasons', marqueeSourceHandlesType('tvmaze', 'season'), true);
check('tvmaze does not handle movies', marqueeSourceHandlesType('tvmaze', 'movie'), false);
check('tvmaze does not handle collections', marqueeSourceHandlesType('tvmaze', 'collection'), false);
check('other sources handle every type', marqueeSourceHandlesType('fanart', 'movie'), true);

// --- Provider verdicts ----------------------------------------------------
check('all ok is not partial',
    marqueeSummariseProviders(['tmdb' => 'ok', 'fanart.tv' => 'ok']), 'ok');
check('no_data is not a failure',
    marqueeSummariseProviders(['tmdb' => 'ok', 'fanart.tv' => 'no_data']), 'ok');
check('one failure of several is partial',
    marqueeSummariseProviders(['tmdb' => 'ok', 'fanart.tv' => 'error']), 'partial');
check('failure alongside no_data is partial',
    marqueeSummariseProviders(['tmdb' => 'no_data', 'fanart.tv' => 'error']), 'partial');
check('every queried source failing is all_failed',
    marqueeSummariseProviders(['tmdb' => 'error', 'fanart.tv' => 'error']), 'all_failed');
check('skipped sources never make a response partial',
    marqueeSummariseProviders(['tmdb' => 'ok', 'fanart.tv' => 'skipped']), 'ok');
check('a lone failure with the rest skipped is all_failed',
    marqueeSummariseProviders(['tmdb' => 'error', 'fanart.tv' => 'skipped']), 'all_failed');
check('everything skipped is not a failure',
    marqueeSummariseProviders(['tmdb' => 'skipped', 'fanart.tv' => 'skipped']), 'ok');

// --- Storage --------------------------------------------------------------
require_once $lib . 'store.php';
$key = 'test:' . bin2hex(random_bytes(6));
marqueeStoreSet($key, ['hello' => 'world'], 60);
check('cache round-trips', marqueeStoreGet($key), ['hello' => 'world']);
check('cache miss returns null', marqueeStoreGet('test:absent:' . bin2hex(random_bytes(6))), null);

marqueeStoreSet($key . ':expired', ['stale' => true], -1);
check('expired entry reads as a miss', marqueeStoreGet($key . ':expired'), null);

$counterKey = 'test:counter:' . bin2hex(random_bytes(6));
check('counter starts at 1', marqueeStoreIncrement($counterKey, 60), 1);
check('counter increments', marqueeStoreIncrement($counterKey, 60), 2);
check('counter increments again', marqueeStoreIncrement($counterKey, 60), 3);

// --- Request parsing ------------------------------------------------------
// Run in a subprocess: marqueeParseRequest() answers a bad parameter by emitting
// the failure envelope and exiting, which would take the whole run with it.
function parseRequest(array $get): array
{
    $lib = __DIR__ . '/../lib/';
    $code = 'define("MARQUEE_API_V2", true);'
        . 'require ' . var_export($lib . 'config.php', true) . ';'
        . 'require ' . var_export($lib . 'response.php', true) . ';'
        . 'require ' . var_export($lib . 'request.php', true) . ';'
        . 'echo json_encode(marqueeParseRequest(' . var_export($get, true) . '));';

    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
    $decoded = json_decode((string) $out, true);

    return is_array($decoded) ? $decoded : ['code' => 'undecodable', 'raw' => $out];
}

$base = ['q' => 'The Matrix', 'type' => 'movie'];

check('tmdb_id absent parses as null', parseRequest($base)['tmdb_id'], null);
check('tmdb_id parses to an int', parseRequest($base + ['tmdb_id' => '603'])['tmdb_id'], 603);
check('tmdb_id tolerates surrounding space', parseRequest($base + ['tmdb_id' => ' 603 '])['tmdb_id'], 603);
check('empty tmdb_id is treated as absent', parseRequest($base + ['tmdb_id' => ''])['tmdb_id'], null);

check('non-numeric tmdb_id is rejected', parseRequest($base + ['tmdb_id' => 'abc'])['code'], 'invalid_request');
check('zero tmdb_id is rejected', parseRequest($base + ['tmdb_id' => '0'])['code'], 'invalid_request');
check('negative tmdb_id is rejected', parseRequest($base + ['tmdb_id' => '-1'])['code'], 'invalid_request');
check('decimal tmdb_id is rejected', parseRequest($base + ['tmdb_id' => '603.5'])['code'], 'invalid_request');

// q stays required: it is the fallback when the identifier is unknown upstream.
check(
    'tmdb_id without q is still rejected',
    parseRequest(['type' => 'movie', 'tmdb_id' => '603'])['code'],
    'invalid_request'
);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
