<?php
// TMDB, TVDB & Fanart.tv Media Poster API - OPTIMIZED VERSION

// Set content type to JSON
header('Content-Type: application/json');

// Enable CORS if needed
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: X-Client-Info');

// Configuration from environment variables
$tmdbApiKey = $_ENV['TMDB_API_KEY'] ?? getenv('TMDB_API_KEY');
$fanartApiKey = $_ENV['FANART_API_KEY'] ?? getenv('FANART_API_KEY');
$apiAccessKey = $_ENV['POSTERIA_API_KEY'] ?? getenv('POSTERIA_API_KEY');

// API base URLs
$tmdbBaseUrl = "https://api.themoviedb.org/3";
$fanartBaseUrl = "https://webservice.fanart.tv/v3";

// TMDB poster configuration
$tmdbPosterSizes = [
    'small' => 'w185',
    'medium' => 'w342',
    'large' => 'w500',
    'original' => 'original'
];
$tmdbPosterBaseUrl = "https://image.tmdb.org/t/p/";

// Trusted client configuration
$trustedClientConfig = [
    'appName' => 'Posteria',
    'headerName' => 'X-Client-Info',
    'timeWindow' => 5 * 60 * 1000,
];

// Global variables for efficiency
$enableDebug = false;
$debugLog = [];
$responseCache = [];

// OPTIMIZATION: Centralized HTTP Client with connection pooling
class HttpClient
{
    private static $instance = null;
    private $multiHandle;

    private function __construct()
    {
        $this->multiHandle = curl_multi_init();
        curl_multi_setopt($this->multiHandle, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
        curl_multi_setopt($this->multiHandle, CURLMOPT_MAX_TOTAL_CONNECTIONS, 10);
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // OPTIMIZATION: Parallel HTTP requests
    public function makeParallelRequests($requests)
    {
        $handles = [];
        $results = [];

        // Add all requests to multi handle
        foreach ($requests as $key => $request) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $request['url'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => 'Posteria/1.0',
                CURLOPT_FOLLOWLOCATION => true
            ]);

            if (isset($request['headers'])) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $request['headers']);
            }

            curl_multi_add_handle($this->multiHandle, $ch);
            $handles[$key] = $ch;
        }

        // Execute all requests
        $running = null;
        do {
            curl_multi_exec($this->multiHandle, $running);
            curl_multi_select($this->multiHandle);
        } while ($running > 0);

        // Collect results
        foreach ($handles as $key => $ch) {
            $content = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            $results[$key] = [
                'content' => $content,
                'http_code' => $httpCode,
                'success' => $httpCode >= 200 && $httpCode < 300 && $content !== false
            ];

            curl_multi_remove_handle($this->multiHandle, $ch);
            curl_close($ch);
        }

        return $results;
    }

    public function __destruct()
    {
        if ($this->multiHandle) {
            curl_multi_close($this->multiHandle);
        }
    }
}

// Function to send error response and exit
function sendError($message, $httpCode = 400)
{
    http_response_code($httpCode);
    echo json_encode([
        'success' => false,
        'error' => $message
    ], JSON_PRETTY_PRINT);
    exit;
}

// Function to log debug messages
function debugLog($message, $data = null)
{
    global $enableDebug, $debugLog;
    if ($enableDebug) {
        $debugLog[] = [
            'message' => $message,
            'data' => $data,
            'time' => microtime(true)
        ];
    }
}

// OPTIMIZATION: Cached response function
function getCachedResponse($key)
{
    global $responseCache;
    return isset($responseCache[$key]) ? $responseCache[$key] : null;
}

function setCachedResponse($key, $data)
{
    global $responseCache;
    $responseCache[$key] = $data;
}

// Function to validate the client info header
function isValidClientInfo($headerValue)
{
    global $trustedClientConfig;

    if (empty($headerValue))
        return false;

    try {
        $decoded = base64_decode($headerValue);
        if ($decoded === false)
            return false;

        $data = json_decode($decoded, true);
        if ($data === null)
            return false;

        if (!isset($data['name']) || $data['name'] !== $trustedClientConfig['appName']) {
            return false;
        }

        if (!isset($data['ts']) || !is_numeric($data['ts']))
            return false;

        $timestamp = (int) $data['ts'];
        $now = round(microtime(true) * 1000);
        $windowStart = $now - $trustedClientConfig['timeWindow'];

        return $timestamp >= $windowStart && $timestamp <= $now;
    } catch (Exception $e) {
        return false;
    }
}

// Check authentication
$isAuthenticated = false;

$headers = getallheaders();
$clientInfoHeader = isset($headers[$trustedClientConfig['headerName']]) ?
    $headers[$trustedClientConfig['headerName']] :
    (isset($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $trustedClientConfig['headerName']))]) ?
        $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $trustedClientConfig['headerName']))] : null);

if ($clientInfoHeader && isValidClientInfo($clientInfoHeader)) {
    $isAuthenticated = true;
}

if (!$isAuthenticated) {
    if (!isset($_GET['key']) || $_GET['key'] !== $apiAccessKey) {
        sendError('Authentication required', 401);
    }
    $isAuthenticated = true;
}

// OPTIMIZATION: Batch TMDB requests
function makeTmdbRequest($endpoint, $params = [])
{
    global $tmdbApiKey, $tmdbBaseUrl;

    $cacheKey = md5($endpoint . serialize($params));
    $cached = getCachedResponse($cacheKey);
    if ($cached)
        return $cached;

    $params['api_key'] = $tmdbApiKey;
    $queryString = http_build_query($params);
    $url = "{$tmdbBaseUrl}{$endpoint}?{$queryString}";

    $httpClient = HttpClient::getInstance();
    $results = $httpClient->makeParallelRequests(['request' => ['url' => $url]]);

    if ($results['request']['success']) {
        $data = json_decode($results['request']['content'], true);
        setCachedResponse($cacheKey, $data);
        return $data;
    }

    return ['success' => false, 'error' => 'API request failed'];
}

// Search functions using optimized requests
function searchMovies($query)
{
    return makeTmdbRequest("/search/movie", [
        'query' => $query,
        'include_adult' => 'false',
        'language' => 'en-US',
        'page' => 1
    ]);
}

function searchTVShows($query)
{
    return makeTmdbRequest("/search/tv", [
        'query' => $query,
        'include_adult' => 'false',
        'language' => 'en-US',
        'page' => 1
    ]);
}

function searchMulti($query)
{
    return makeTmdbRequest("/search/multi", [
        'query' => $query,
        'include_adult' => 'false',
        'language' => 'en-US',
        'page' => 1
    ]);
}

function searchCollections($query)
{
    return makeTmdbRequest("/search/collection", [
        'query' => $query,
        'language' => 'en-US',
        'page' => 1
    ]);
}

// Get detailed info functions
function getMovieDetails($movieId)
{
    return makeTmdbRequest("/movie/{$movieId}", [
        'language' => 'en-US',
        'append_to_response' => 'external_ids'
    ]);
}

function getTVDetails($tvId)
{
    return makeTmdbRequest("/tv/{$tvId}", [
        'language' => 'en-US',
        'append_to_response' => 'external_ids'
    ]);
}

function getCollectionDetails($collectionId)
{
    return makeTmdbRequest("/collection/{$collectionId}", [
        'language' => 'en-US'
    ]);
}

function getSeasonDetails($tvId, $seasonNumber)
{
    return makeTmdbRequest("/tv/{$tvId}/season/{$seasonNumber}", [
        'language' => 'en-US'
    ]);
}

// OPTIMIZATION: Get all images in one call
function getMovieImages($movieId)
{
    return makeTmdbRequest("/movie/{$movieId}/images", [
        'include_image_language' => 'en,null'
    ]);
}

function getTVImages($tvId)
{
    return makeTmdbRequest("/tv/{$tvId}/images", [
        'include_image_language' => 'en,null'
    ]);
}

function getCollectionImages($collectionId)
{
    return makeTmdbRequest("/collection/{$collectionId}/images", [
        'include_image_language' => 'en,null'
    ]);
}

// OPTIMIZATION: Parallel external API calls
function fetchExternalData($requests)
{
    $httpClient = HttpClient::getInstance();
    return $httpClient->makeParallelRequests($requests);
}

// Format poster URLs
function formatTmdbPosterUrls($posterPath)
{
    global $tmdbPosterBaseUrl, $tmdbPosterSizes;

    if (empty($posterPath))
        return null;

    $urls = [];
    foreach ($tmdbPosterSizes as $size => $sizeCode) {
        $urls[$size] = $tmdbPosterBaseUrl . $sizeCode . $posterPath;
    }
    return $urls;
}

function formatFanartPosterUrls($url)
{
    return [
        'small' => $url,
        'medium' => $url,
        'large' => $url,
        'original' => $url
    ];
}

function formatTvdbPosterUrls($url)
{
    return [
        'small' => $url,
        'medium' => $url,
        'large' => $url,
        'original' => $url
    ];
}

// OPTIMIZATION: Improved external data fetching functions
function getExternalMovieData($tmdbId)
{
    global $fanartApiKey, $fanartBaseUrl;

    $requests = [];
    $requests['fanart'] = [
        'url' => "{$fanartBaseUrl}/movies/{$tmdbId}?api_key={$fanartApiKey}"
    ];

    return fetchExternalData($requests);
}

function getExternalTVData($tmdbId, $tvdbId)
{
    global $fanartApiKey, $fanartBaseUrl;

    $requests = [];

    if ($tvdbId) {
        $requests['fanart'] = [
            'url' => "{$fanartBaseUrl}/tv/{$tvdbId}?api_key={$fanartApiKey}"
        ];
    }

    return fetchExternalData($requests);
}

// OPTIMIZATION: Improved TheTVDB function with parallel requests
function getTvdbPosters($title, $contentType, $seasonNumber = null)
{
    $slug = strtolower(str_replace(' ', '-', $title));
    $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);

    $requests = [];

    if ($contentType === 'movie') {
        $requests['main'] = ['url' => "https://www.thetvdb.com/movies/{$slug}"];
    } else {
        $requests['main'] = ['url' => "https://www.thetvdb.com/series/{$slug}"];

        if ($seasonNumber !== null && $seasonNumber > 0) {
            $requests['season_official'] = ['url' => "https://www.thetvdb.com/series/{$slug}/seasons/official/{$seasonNumber}"];
            $requests['season_dvd'] = ['url' => "https://www.thetvdb.com/series/{$slug}/seasons/dvd/{$seasonNumber}"];
        }

        $requests['specials'] = ['url' => "https://www.thetvdb.com/series/{$slug}/seasons/official/0"];
    }

    $results = fetchExternalData($requests);
    $posters = [];

    foreach ($results as $key => $result) {
        if (!$result['success'])
            continue;

        $html = $result['content'];
        $extractedPosters = extractPosterUrls($html, $contentType, $seasonNumber);
        $posters = array_merge($posters, $extractedPosters);
    }

    // Remove duplicates and filter
    $posters = array_unique($posters);
    $posters = array_filter($posters, function ($posterUrl) {
        return stripos($posterUrl, 'unknown') === false;
    });

    return ['posters' => $posters, 'attempted_urls' => array_keys($requests)];
}

// Extract poster URLs from HTML
function extractPosterUrls($html, $contentType, $seasonNumber = null)
{
    $posters = [];

    $pattern = '/src="(https:\/\/artworks\.thetvdb\.com\/banners\/(?!.*banners\/)[^"]+)"/i';
    preg_match_all($pattern, $html, $matches);

    if (isset($matches[1])) {
        foreach ($matches[1] as $imageUrl) {
            if (stripos($imageUrl, 'unknown') !== false)
                continue;
            if (stripos($imageUrl, 'backgrounds') !== false)
                continue;

            $isRelevantImage = false;

            if ($contentType === 'movie') {
                $isRelevantImage =
                    strpos($imageUrl, 'posters/') !== false ||
                    strpos($imageUrl, 'poster/') !== false ||
                    strpos($imageUrl, 'poster_') !== false;
            } else if ($contentType === 'series') {
                if ($seasonNumber !== null) {
                    $seasonPatterns = [
                        "/season[-_.\s]{$seasonNumber}/i",
                        "/s[-_.\s]?{$seasonNumber}/i",
                        "/s0*{$seasonNumber}[-_]/i",
                        "/s0*{$seasonNumber}\./i",
                        "/{$seasonNumber}(st|nd|rd|th)/i",
                        "/season.*?{$seasonNumber}/i"
                    ];

                    if ($seasonNumber === 0) {
                        $seasonPatterns[] = "/specials/i";
                        $seasonPatterns[] = "/special[-_.\s]/i";
                        $seasonPatterns[] = "/s00/i";
                        $seasonPatterns[] = "/s0[-_.\s]/i";
                    }

                    foreach ($seasonPatterns as $pattern) {
                        if (preg_match($pattern, $imageUrl)) {
                            $isRelevantImage = true;
                            break;
                        }
                    }

                    if (
                        strpos($html, "/seasons/official/{$seasonNumber}") !== false ||
                        strpos($html, "/seasons/dvd/{$seasonNumber}") !== false
                    ) {
                        if (
                            strpos($imageUrl, 'posters/') !== false ||
                            strpos($imageUrl, 'poster/') !== false ||
                            strpos($imageUrl, 'poster_') !== false
                        ) {
                            $isRelevantImage = true;
                        }
                    }
                } else {
                    $isRelevantImage =
                        (strpos($imageUrl, 'posters/') !== false &&
                            !preg_match('/seasons?[^\/]*\d+|s\d+[-_]|s0\d+/i', $imageUrl)) ||
                        (strpos($imageUrl, 'poster/') !== false &&
                            !preg_match('/seasons?[^\/]*\d+|s\d+[-_]|s0\d+/i', $imageUrl));
                }
            }

            if ($isRelevantImage && !in_array($imageUrl, $posters) && strpos($imageUrl, 'seasonswide') === false) {
                $posters[] = $imageUrl;
            }
        }
    }

    return $posters;
}

// Helper functions
function extractSeasonNumber($query)
{
    if (preg_match('/(season|s)\s*(\d+)/i', $query, $matches)) {
        return intval($matches[2]);
    }
    return null;
}

function cleanShowName($query)
{
    return trim(preg_replace('/(season|s)\s*\d+/i', '', $query));
}

function findMovieByName($name)
{
    $searchResults = searchMovies($name);
    if (!empty($searchResults['results'])) {
        return $searchResults['results'][0]['id'];
    }
    return null;
}

function getPosterFilename($poster)
{
    if (!isset($poster['original']))
        return '';
    $url = $poster['original'];
    $pathParts = explode('/', $url);
    return end($pathParts);
}

// Process request
$response = [
    'success' => true,
    'query' => '',
    'results' => []
];

// Get query parameters
$searchTerm = isset($_GET['q']) ? trim($_GET['q']) : '';
$mediaType = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : 'all';
$showSeasons = isset($_GET['show_seasons']) && ($_GET['show_seasons'] === 'true' || $_GET['show_seasons'] === '1');
$specificSeason = isset($_GET['season']) ? intval($_GET['season']) : null;
$includeAllPosters = isset($_GET['include_all_posters']) && ($_GET['include_all_posters'] === 'true' || $_GET['include_all_posters'] === '1');
$includeTvdb = isset($_GET['include_tvdb']) ? ($_GET['include_tvdb'] === 'true' || $_GET['include_tvdb'] === '1') : true;

$enableDebug = isset($_GET['debug']) && ($_GET['debug'] === 'true' || $_GET['debug'] === '1');

// Parse season number from query if not explicitly provided
$extractedSeason = extractSeasonNumber($searchTerm);
if ($extractedSeason !== null && $specificSeason === null) {
    $specificSeason = $extractedSeason;
    $cleanedShowName = cleanShowName($searchTerm);
    if (!empty($cleanedShowName)) {
        $searchTerm = $cleanedShowName;
    }
    if ($mediaType === 'all') {
        $mediaType = 'tv';
    }
}

// Validate input
if (!in_array($mediaType, ['movie', 'tv', 'collection', 'all'])) {
    sendError('Invalid type parameter. Use "movie", "tv", "collection", or "all".');
}

if (isset($_GET['movie']) && empty($searchTerm)) {
    $searchTerm = trim($_GET['movie']);
    $mediaType = 'movie';
}

if (empty($searchTerm)) {
    sendError('Missing required parameter: q (or movie for backwards compatibility)');
}

$response['query'] = $searchTerm;
$response['type'] = $mediaType;

if ($specificSeason !== null) {
    $response['requested_season'] = $specificSeason;
}

// Search based on media type
$searchResults = [];
if ($mediaType === 'movie') {
    $searchResults = searchMovies($searchTerm);
} elseif ($mediaType === 'tv') {
    $searchResults = searchTVShows($searchTerm);
} elseif ($mediaType === 'collection') {
    $searchResults = searchCollections($searchTerm);
} else { // 'all'
    $searchResults = searchMulti($searchTerm);

    // Add collection results
    $collectionResults = searchCollections($searchTerm);
    if (isset($collectionResults['results']) && !empty($collectionResults['results'])) {
        foreach ($collectionResults['results'] as &$result) {
            $result['media_type'] = 'collection';
        }
        if (isset($searchResults['results'])) {
            $searchResults['results'] = array_merge($searchResults['results'], $collectionResults['results']);
        } else {
            $searchResults['results'] = $collectionResults['results'];
        }
    }
}

// Check for API errors
if (isset($searchResults['success']) && $searchResults['success'] === false) {
    sendError($searchResults['error']);
}

if (empty($searchResults['results'])) {
    $response['success'] = true;
    $response['message'] = 'No results found matching the query';
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
}

// OPTIMIZATION: Group results by type for batch processing
$movieResults = [];
$tvResults = [];
$collectionResultsList = [];

foreach ($searchResults['results'] as $result) {
    $type = $mediaType === 'all' ? (isset($result['media_type']) ? $result['media_type'] : $mediaType) : $mediaType;
    if ($type === 'person')
        continue;

    switch ($type) {
        case 'movie':
            $movieResults[] = $result;
            break;
        case 'tv':
            $tvResults[] = $result;
            break;
        case 'collection':
            $collectionResultsList[] = $result;
            break;
    }
}

// Process movie results
foreach ($movieResults as $result) {
    $tmdbId = $result['id'];

    // Get movie details and images in parallel
    $movieDetails = getMovieDetails($tmdbId);
    $movieImages = getMovieImages($tmdbId);

    if (!$movieDetails || (isset($movieDetails['success']) && !$movieDetails['success'])) {
        continue;
    }

    $baseItem = [
        'id' => $tmdbId,
        'type' => 'movie',
        'title' => $result['title'],
        'release_date' => isset($result['release_date']) ? $result['release_date'] : null,
    ];

    if (isset($movieDetails['external_ids']['imdb_id'])) {
        $baseItem['imdb_id'] = $movieDetails['external_ids']['imdb_id'];
    }

    // Get external data in parallel
    $externalData = getExternalMovieData($tmdbId);

    // Process fanart.tv posters
    if (isset($externalData['fanart']) && $externalData['fanart']['success']) {
        $fanartData = json_decode($externalData['fanart']['content'], true);
        if (!empty($fanartData) && isset($fanartData['movieposter'])) {
            foreach ($fanartData['movieposter'] as $poster) {
                $item = $baseItem;
                $item['poster'] = formatFanartPosterUrls($poster['url']);
                $item['source'] = 'fanart.tv';
                $response['results'][] = $item;
            }
        }
    }

    // Add TMDB main poster
    if (!empty($result['poster_path'])) {
        $item = $baseItem;
        $item['poster'] = formatTmdbPosterUrls($result['poster_path']);
        $item['source'] = 'tmdb';
        $response['results'][] = $item;
    }

    // Add all TMDB image posters
    if (!empty($movieImages) && isset($movieImages['posters'])) {
        foreach ($movieImages['posters'] as $poster) {
            if ($poster['file_path'] === $result['poster_path'])
                continue;

            $item = $baseItem;
            $item['poster'] = formatTmdbPosterUrls($poster['file_path']);
            $item['source'] = 'tmdb';
            $response['results'][] = $item;
        }
    }

    // Add TheTVDB posters
    if ($includeTvdb) {
        $tvdbResults = getTvdbPosters($result['title'], 'movie');
        foreach ($tvdbResults['posters'] as $posterUrl) {
            $item = $baseItem;
            $item['poster'] = formatTvdbPosterUrls($posterUrl);
            $item['source'] = 'thetvdb';
            $response['results'][] = $item;
        }
    }
}

// Process TV results
foreach ($tvResults as $result) {
    $tmdbId = $result['id'];

    // Get TV details and images in parallel
    $tvDetails = getTVDetails($tmdbId);
    $tvImages = getTVImages($tmdbId);

    if (!$tvDetails || (isset($tvDetails['success']) && !$tvDetails['success'])) {
        continue;
    }

    $baseItem = [
        'id' => $tmdbId,
        'type' => 'tv',
        'title' => $result['name'],
        'first_air_date' => isset($result['first_air_date']) ? $result['first_air_date'] : null,
    ];

    $tvdbId = isset($tvDetails['external_ids']['tvdb_id']) ? $tvDetails['external_ids']['tvdb_id'] : null;
    if ($tvdbId) {
        $baseItem['tvdb_id'] = $tvdbId;
    }

    // Get external data in parallel
    $externalData = getExternalTVData($tmdbId, $tvdbId);

    $seenSeasonPosters = [];

    // Process fanart.tv TV posters
    if (isset($externalData['fanart']) && $externalData['fanart']['success']) {
        $fanartData = json_decode($externalData['fanart']['content'], true);
        if (!empty($fanartData) && isset($fanartData['tvposter'])) {
            foreach ($fanartData['tvposter'] as $poster) {
                $item = $baseItem;
                $item['poster'] = formatFanartPosterUrls($poster['url']);
                $item['source'] = 'fanart.tv';

                if ($specificSeason !== null) {
                    $item = addSeasonInfoToItem($item, $tvDetails, $specificSeason, $seenSeasonPosters);
                }

                $response['results'][] = $item;
            }
        }

        // Process fanart.tv season posters
        if ($specificSeason !== null && !empty($fanartData) && isset($fanartData['seasonposter'])) {
            foreach ($fanartData['seasonposter'] as $seasonPoster) {
                if (isset($seasonPoster['season']) && $seasonPoster['season'] == $specificSeason) {
                    $item = $baseItem;

                    if (!empty($result['poster_path'])) {
                        $item['poster'] = formatTmdbPosterUrls($result['poster_path']);
                        $item['source'] = 'tmdb';
                    } elseif (!empty($fanartData['tvposter'])) {
                        $item['poster'] = formatFanartPosterUrls($fanartData['tvposter'][0]['url']);
                        $item['source'] = 'fanart.tv';
                    }

                    $seasonData = [
                        'season_number' => $specificSeason,
                        'name' => "Season $specificSeason"
                    ];

                    if (isset($tvDetails['seasons'])) {
                        foreach ($tvDetails['seasons'] as $season) {
                            if ($season['season_number'] == $specificSeason) {
                                $seasonData['name'] = $season['name'];
                                $seasonData['episode_count'] = $season['episode_count'];
                                $seasonData['air_date'] = isset($season['air_date']) ? $season['air_date'] : null;
                                break;
                            }
                        }
                    }

                    $formattedPoster = formatFanartPosterUrls($seasonPoster['url']);
                    $posterFilename = getPosterFilename($formattedPoster);

                    if (!in_array($posterFilename, $seenSeasonPosters)) {
                        $seenSeasonPosters[] = $posterFilename;
                        $seasonData['poster'] = $formattedPoster;
                        $seasonData['poster_source'] = 'fanart.tv';
                        $item['season'] = $seasonData;
                        $response['results'][] = $item;
                    }
                }
            }
        }
    }

    // Add TMDB main poster
    if (!empty($result['poster_path'])) {
        $item = $baseItem;
        $item['poster'] = formatTmdbPosterUrls($result['poster_path']);
        $item['source'] = 'tmdb';

        if ($specificSeason !== null) {
            $item = addSeasonInfoToItem($item, $tvDetails, $specificSeason, $seenSeasonPosters);
        }

        $response['results'][] = $item;
    }

    // Add all TMDB image posters
    if (!empty($tvImages) && isset($tvImages['posters'])) {
        foreach ($tvImages['posters'] as $poster) {
            if ($poster['file_path'] === $result['poster_path'])
                continue;

            $item = $baseItem;
            $item['poster'] = formatTmdbPosterUrls($poster['file_path']);
            $item['source'] = 'tmdb';

            if ($specificSeason !== null) {
                $item = addSeasonInfoToItem($item, $tvDetails, $specificSeason);
            }

            $response['results'][] = $item;
        }
    }

    // Add TheTVDB posters
    if ($includeTvdb) {
        $tvdbResults = getTvdbPosters($result['name'], 'tv', $specificSeason);
        foreach ($tvdbResults['posters'] as $posterUrl) {
            $item = $baseItem;
            $item['poster'] = formatTvdbPosterUrls($posterUrl);
            $item['source'] = 'thetvdb';

            if ($specificSeason !== null) {
                $seasonData = [
                    'season_number' => $specificSeason,
                    'name' => "Season $specificSeason"
                ];

                if (isset($tvDetails['seasons'])) {
                    foreach ($tvDetails['seasons'] as $season) {
                        if ($season['season_number'] == $specificSeason) {
                            $seasonData['name'] = $season['name'];
                            $seasonData['episode_count'] = $season['episode_count'];
                            $seasonData['air_date'] = isset($season['air_date']) ? $season['air_date'] : null;
                            break;
                        }
                    }
                }

                $isSeasonPoster = preg_match('/season[^\/]*' . $specificSeason . '|s' . $specificSeason . '[^\/]|s0?' . $specificSeason . '[^\/]/i', $posterUrl);
                if ($isSeasonPoster) {
                    $seasonData['poster'] = formatTvdbPosterUrls($posterUrl);
                    $seasonData['poster_source'] = 'thetvdb';
                }

                $item['season'] = $seasonData;
            }

            $response['results'][] = $item;
        }
    }

    // Add season episodes if requested
    if ($showSeasons && $specificSeason !== null) {
        $seasonDetails = getSeasonDetails($tmdbId, $specificSeason);
        if (isset($seasonDetails['episodes'])) {
            foreach ($response['results'] as &$resItem) {
                if (
                    $resItem['id'] == $tmdbId && isset($resItem['season']) &&
                    is_array($resItem['season']) && !isset($resItem['season']['episodes'])
                ) {

                    $episodes = [];
                    foreach ($seasonDetails['episodes'] as $episode) {
                        $episodeData = [
                            'episode_number' => $episode['episode_number'],
                            'name' => $episode['name'],
                            'air_date' => isset($episode['air_date']) ? $episode['air_date'] : null
                        ];

                        if (!empty($episode['still_path'])) {
                            $episodeData['still'] = formatTmdbPosterUrls($episode['still_path']);
                            $episodeData['still_source'] = 'tmdb';
                        }

                        $episodes[] = $episodeData;
                    }

                    $resItem['season']['episodes'] = $episodes;
                    break;
                }
            }
        }
    }
}

// Process collection results
foreach ($collectionResultsList as $result) {
    if (empty($result['poster_path']))
        continue;

    $collectionId = $result['id'];
    $collectionName = isset($result['name']) ? $result['name'] : $result['title'];

    $baseItem = [
        'id' => $collectionId,
        'type' => 'collection',
        'title' => $collectionName,
    ];

    // Get collection details and images
    $collectionDetails = getCollectionDetails($collectionId);
    $collectionImages = getCollectionImages($collectionId);

    // Add TMDB main poster
    if (!empty($result['poster_path'])) {
        $item = $baseItem;
        $item['poster'] = formatTmdbPosterUrls($result['poster_path']);
        $item['source'] = 'tmdb';
        $response['results'][] = $item;
    }

    // Add all TMDB collection image posters
    if (!empty($collectionImages) && isset($collectionImages['posters'])) {
        foreach ($collectionImages['posters'] as $poster) {
            if ($poster['file_path'] === $result['poster_path'])
                continue;

            $item = $baseItem;
            $item['poster'] = formatTmdbPosterUrls($poster['file_path']);
            $item['source'] = 'tmdb';
            $response['results'][] = $item;
        }
    }

    // Try to find collection on fanart.tv
    $searchName = $collectionName;
    if (stripos($collectionName, 'Collection') === false) {
        $searchName .= ' Collection';
    }

    $collectionMovieId = findMovieByName($searchName);
    if ($collectionMovieId) {
        $collectionExternalData = getExternalMovieData($collectionMovieId);
        if (isset($collectionExternalData['fanart']) && $collectionExternalData['fanart']['success']) {
            $fanartData = json_decode($collectionExternalData['fanart']['content'], true);
            if (!empty($fanartData) && isset($fanartData['movieposter'])) {
                foreach ($fanartData['movieposter'] as $poster) {
                    $item = $baseItem;
                    $item['poster'] = formatFanartPosterUrls($poster['url']);
                    $item['source'] = 'fanart.tv';
                    $response['results'][] = $item;
                }
            }
        }
    }

    // If no collection-specific posters, use first movie posters
    if ($collectionMovieId === null && isset($collectionDetails['parts']) && !empty($collectionDetails['parts'])) {
        $firstMovie = $collectionDetails['parts'][0];
        $firstMovieExternalData = getExternalMovieData($firstMovie['id']);
        if (isset($firstMovieExternalData['fanart']) && $firstMovieExternalData['fanart']['success']) {
            $fanartData = json_decode($firstMovieExternalData['fanart']['content'], true);
            if (!empty($fanartData) && isset($fanartData['movieposter'])) {
                foreach ($fanartData['movieposter'] as $poster) {
                    $item = $baseItem;
                    $item['poster'] = formatFanartPosterUrls($poster['url']);
                    $item['source'] = 'fanart.tv';
                    $response['results'][] = $item;
                }
            }
        }
    }

    // Add TheTVDB posters
    if ($includeTvdb) {
        $tvdbResults = getTvdbPosters($collectionName, 'movie');
        foreach ($tvdbResults['posters'] as $posterUrl) {
            $item = $baseItem;
            $item['poster'] = formatTvdbPosterUrls($posterUrl);
            $item['source'] = 'thetvdb';
            $response['results'][] = $item;
        }
    }
}

// Helper function to add season info to item
function addSeasonInfoToItem($item, $tvDetails, $specificSeason, &$seenSeasonPosters = [])
{
    if (isset($tvDetails['seasons'])) {
        foreach ($tvDetails['seasons'] as $season) {
            if ($season['season_number'] == $specificSeason) {
                $seasonData = [
                    'season_number' => $season['season_number'],
                    'name' => $season['name'],
                    'episode_count' => $season['episode_count'],
                    'air_date' => isset($season['air_date']) ? $season['air_date'] : null
                ];

                if (!empty($season['poster_path'])) {
                    $seasonPoster = formatTmdbPosterUrls($season['poster_path']);
                    $posterFilename = getPosterFilename($seasonPoster);

                    if (!in_array($posterFilename, $seenSeasonPosters)) {
                        $seenSeasonPosters[] = $posterFilename;
                        $seasonData['poster'] = $seasonPoster;
                        $seasonData['poster_source'] = 'tmdb';
                    }
                }

                $item['season'] = $seasonData;
                break;
            }
        }

        if (!isset($item['season'])) {
            $item['season'] = null;
            $item['season_not_found'] = true;
        }
    }

    return $item;
}

// Filter out null results and set count
$response['results'] = array_filter($response['results']);
$response['count'] = count($response['results']);

// Add help if requested
if (isset($_GET['help']) && $_GET['help'] === 'true') {
    $response['parameters'] = [
        'q' => 'Search query (required)',
        'type' => 'Media type: movie, tv, collection, or all (default: all)',
        'season' => 'Season number for TV shows (optional)',
        'show_seasons' => 'Include season details for TV shows (true/false)',
        'include_all_posters' => 'Include all available posters (true/false)',
        'include_tvdb' => 'Include TheTVDB posters (true/false, default: true)',
        'debug' => 'Enable debug mode (true/false)',
        'key' => 'API key for authentication (required if not using X-Client-Info header)',
        'help' => 'Show this help information (true/false)'
    ];

    $response['sources'] = [
        'tmdb' => 'The Movie Database (TMDB)',
        'fanart.tv' => 'Fanart.tv',
        'thetvdb' => 'TheTVDB'
    ];
}

// Add debug info if enabled
if ($enableDebug) {
    $response['debug'] = $debugLog;
    $response['optimizations'] = [
        'parallel_requests' => 'Enabled',
        'response_caching' => 'Enabled',
        'connection_pooling' => 'Enabled'
    ];
}

// Optional response caching
$cacheDuration = isset($_GET['cache']) ? intval($_GET['cache']) : 0;
if ($cacheDuration > 0) {
    header('Cache-Control: public, max-age=' . $cacheDuration);
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
