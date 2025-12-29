<?php
// TMDB, TVDB & Fanart.tv Media Poster API - Optimized Version (Preserving All Results)

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

// Enable debug logging
$enableDebug = false;
$debugLog = [];

// Multi-cURL handler for parallel requests
$multiHandle = null;
$curlHandles = [];

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

// Function to validate the client info header
function isValidClientInfo($headerValue)
{
    global $trustedClientConfig;

    if (empty($headerValue)) {
        return false;
    }

    try {
        $decoded = base64_decode($headerValue);
        if ($decoded === false) {
            return false;
        }

        $data = json_decode($decoded, true);
        if ($data === null) {
            return false;
        }

        if (!isset($data['name']) || $data['name'] !== $trustedClientConfig['appName']) {
            return false;
        }

        if (!isset($data['ts']) || !is_numeric($data['ts'])) {
            return false;
        }

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

// If not authenticated via header, check for API key (fallback)
if (!$isAuthenticated) {
    if (!isset($_GET['key'])) {
        sendError('Authentication required', 401);
    }

    if ($_GET['key'] !== $apiAccessKey) {
        sendError('Invalid authentication', 403);
    }

    $isAuthenticated = true;
}

// Optimized cURL functions for parallel processing
function initMultiCurl()
{
    global $multiHandle;
    if ($multiHandle === null) {
        $multiHandle = curl_multi_init();
    }
}

function createCurlHandle($url, $options = [])
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Posteria/1.0');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    foreach ($options as $option => $value) {
        curl_setopt($ch, $option, $value);
    }
    
    return $ch;
}

function executeBatchRequests($requests)
{
    global $multiHandle, $curlHandles;
    
    if (empty($requests)) {
        return [];
    }
    
    initMultiCurl();
    $curlHandles = [];
    $responses = [];
    
    // Add all requests to multi handle
    foreach ($requests as $key => $request) {
        $ch = createCurlHandle($request['url'], $request['options'] ?? []);
        $curlHandles[$key] = $ch;
        curl_multi_add_handle($multiHandle, $ch);
    }
    
    // Execute all requests
    $running = null;
    do {
        curl_multi_exec($multiHandle, $running);
        curl_multi_select($multiHandle);
    } while ($running > 0);
    
    // Collect responses
    foreach ($curlHandles as $key => $ch) {
        $response = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode >= 200 && $httpCode < 300 && $response) {
            $responses[$key] = $response;
        } else {
            $responses[$key] = null;
        }
        
        curl_multi_remove_handle($multiHandle, $ch);
        curl_close($ch);
    }
    
    return $responses;
}

// TMDB request functions (optimized with batching)
function makeTmdbRequestBatch($endpoints)
{
    global $tmdbApiKey, $tmdbBaseUrl;
    
    if (empty($endpoints)) {
        return [];
    }
    
    $requests = [];
    foreach ($endpoints as $key => $endpoint) {
        $params = $endpoint['params'] ?? [];
        $params['api_key'] = $tmdbApiKey;
        
        if (isset($endpoint['append'])) {
            $params['append_to_response'] = $endpoint['append'];
        }
        
        $queryString = http_build_query($params);
        $url = "{$tmdbBaseUrl}{$endpoint['path']}?{$queryString}";
        
        $requests[$key] = ['url' => $url];
    }
    
    $responses = executeBatchRequests($requests);
    $results = [];
    
    foreach ($responses as $key => $response) {
        $results[$key] = $response ? json_decode($response, true) : null;
    }
    
    return $results;
}

function makeTmdbRequest($endpoint, $params = [])
{
    $result = makeTmdbRequestBatch([
        'single' => ['path' => $endpoint, 'params' => $params]
    ]);
    
    return $result['single'] ?? ['success' => false, 'error' => 'Request failed'];
}

// Search functions
function searchMulti($query)
{
    return makeTmdbRequest("/search/multi", [
        'query' => $query,
        'include_adult' => 'false',
        'language' => 'en-US',
        'page' => 1
    ]);
}

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

function searchCollections($query)
{
    return makeTmdbRequest("/search/collection", [
        'query' => $query,
        'language' => 'en-US',
        'page' => 1
    ]);
}

// Function to get movie details from TMDB
function getMovieDetails($movieId)
{
    return makeTmdbRequest("/movie/{$movieId}", [
        'language' => 'en-US',
        'append_to_response' => 'external_ids'
    ]);
}

// Function to get TV show details from TMDB
function getTVDetails($tvId)
{
    return makeTmdbRequest("/tv/{$tvId}", [
        'language' => 'en-US',
        'append_to_response' => 'external_ids'
    ]);
}

// Function to get collection details from TMDB
function getCollectionDetails($collectionId)
{
    return makeTmdbRequest("/collection/{$collectionId}", [
        'language' => 'en-US'
    ]);
}

// ADDED: Function to get all images for a movie from TMDB
function getMovieImages($movieId)
{
    return makeTmdbRequest("/movie/{$movieId}/images", [
        'include_image_language' => 'en,null'
    ]);
}

// ADDED: Function to get all images for a TV show from TMDB
function getTVImages($tvId)
{
    return makeTmdbRequest("/tv/{$tvId}/images", [
        'include_image_language' => 'en,null'
    ]);
}

// ADDED: Function to get all images for a collection from TMDB
function getCollectionImages($collectionId)
{
    return makeTmdbRequest("/collection/{$collectionId}/images", [
        'include_image_language' => 'en,null'
    ]);
}

// Optimized function to get artwork from fanart.tv
function getMovieArtworkBatch($tmdbIds)
{
    global $fanartBaseUrl, $fanartApiKey;
    
    if (empty($tmdbIds)) {
        return [];
    }
    
    $requests = [];
    foreach ($tmdbIds as $key => $tmdbId) {
        $url = "{$fanartBaseUrl}/movies/{$tmdbId}?api_key={$fanartApiKey}";
        $requests[$key] = ['url' => $url];
    }
    
    $responses = executeBatchRequests($requests);
    $results = [];
    
    foreach ($responses as $key => $response) {
        if ($response) {
            $decoded = json_decode($response, true);
            if (!empty($decoded) && (!isset($decoded['status']) || $decoded['status'] !== 'error')) {
                $results[$key] = $decoded;
            } else {
                $results[$key] = [];
            }
        } else {
            $results[$key] = [];
        }
    }
    
    return $results;
}

function getTVArtworkBatch($tvdbIds)
{
    global $fanartBaseUrl, $fanartApiKey;
    
    if (empty($tvdbIds)) {
        return [];
    }
    
    $requests = [];
    foreach ($tvdbIds as $key => $tvdbId) {
        $url = "{$fanartBaseUrl}/tv/{$tvdbId}?api_key={$fanartApiKey}";
        $requests[$key] = ['url' => $url];
    }
    
    $responses = executeBatchRequests($requests);
    $results = [];
    
    foreach ($responses as $key => $response) {
        if ($response) {
            $decoded = json_decode($response, true);
            if (!empty($decoded) && (!isset($decoded['status']) || $decoded['status'] !== 'error')) {
                $results[$key] = $decoded;
            } else {
                $results[$key] = [];
            }
        } else {
            $results[$key] = [];
        }
    }
    
    return $results;
}

// Single fanart.tv functions for compatibility
function getMovieArtwork($tmdbId)
{
    $results = getMovieArtworkBatch([$tmdbId]);
    return $results[0] ?? [];
}

function getTVArtwork($tvdbId)
{
    $results = getTVArtworkBatch([$tvdbId]);
    return $results[0] ?? [];
}

// TheTVDB functions (optimized for batch processing)
function getTvdbPostersBatch($items, $specificSeason = null)
{
    if (empty($items)) {
        return [];
    }
    
    $requests = [];
    $results = [];
    
    foreach ($items as $item) {
        $title = $item['title'];
        $type = $item['type'];
        $key = $item['key'];
        
        $slug = strtolower(str_replace(' ', '-', $title));
        $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
        
        if ($type === 'movie') {
            $url = "https://www.thetvdb.com/movies/{$slug}";
            $requests[$key] = ['url' => $url];
        } elseif ($type === 'tv') {
            if ($specificSeason !== null && $specificSeason > 0) {
                $urlPatterns = [
                    "/seasons/official/{$specificSeason}",
                    "/seasons/dvd/{$specificSeason}",
                    "/seasons"
                ];
                
                $baseUrl = "https://www.thetvdb.com/series/{$slug}";
                foreach ($urlPatterns as $pattern) {
                    $url = $baseUrl . $pattern;
                    $requests["{$key}_" . md5($pattern)] = ['url' => $url];
                }
            } else {
                $url = "https://www.thetvdb.com/series/{$slug}";
                $requests[$key] = ['url' => $url];
                
                $specialsUrl = "https://www.thetvdb.com/series/{$slug}/seasons/official/0";
                $requests["{$key}_specials"] = ['url' => $specialsUrl];
            }
        }
    }
    
    $responses = executeBatchRequests($requests);
    
    foreach ($responses as $responseKey => $html) {
        if ($html) {
            $posters = extractPosterUrls($html, 'auto', $specificSeason);
            $posters = array_filter($posters, function($url) {
                return stripos($url, 'unknown') === false;
            });
            
            if (!empty($posters)) {
                // Find the original key (remove any suffixes we added)
                $originalKey = $responseKey;
                if (strpos($responseKey, '_') !== false) {
                    $parts = explode('_', $responseKey);
                    if (count($parts) >= 2) {
                        $originalKey = $parts[0] . '_' . $parts[1]; // Keep movie_0, tv_1 format
                    }
                }
                
                if (!isset($results[$originalKey])) {
                    $results[$originalKey] = [];
                }
                $results[$originalKey] = array_merge($results[$originalKey], $posters);
            }
        }
    }
    
    // Remove duplicates
    foreach ($results as &$posters) {
        $posters = array_unique($posters);
    }
    
    return $results;
}

// Original TheTVDB function for single requests
function getTvdbPosters($title, $contentType, $seasonNumber = null)
{
    $items = [[
        'title' => $title,
        'type' => $contentType,
        'key' => 'single'
    ]];
    
    $results = getTvdbPostersBatch($items, $seasonNumber);
    $posters = $results['single'] ?? [];
    
    return ['posters' => $posters, 'attempted_urls' => []];
}

// Extract poster URLs from HTML (same as original)
function extractPosterUrls($html, $contentType, $seasonNumber = null)
{
    $posters = [];
    
    $pattern = '/src="(https:\/\/artworks\.thetvdb\.com\/banners\/(?!.*banners\/)[^"]+)"/i';
    preg_match_all($pattern, $html, $matches);
    
    if (isset($matches[1])) {
        foreach ($matches[1] as $imageUrl) {
            if (stripos($imageUrl, 'unknown') !== false || 
                stripos($imageUrl, 'backgrounds') !== false ||
                stripos($imageUrl, 'seasonswide') !== false) {
                continue;
            }
            
            $isRelevantImage = false;
            
            if ($contentType === 'movie') {
                $isRelevantImage =
                    strpos($imageUrl, 'posters/') !== false ||
                    strpos($imageUrl, 'poster/') !== false ||
                    strpos($imageUrl, 'poster_') !== false;
            } else if ($contentType === 'series' || $contentType === 'tv' || $contentType === 'auto') {
                if ($seasonNumber !== null) {
                    $seasonPatterns = [
                        "/season[-_.\s]{$seasonNumber}/i",
                        "/s[-_.\s]?{$seasonNumber}/i",
                        "/s0*{$seasonNumber}[-_.]/i",
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
                    
                    if (!$isRelevantImage && (
                        strpos($html, "/seasons/official/{$seasonNumber}") !== false ||
                        strpos($html, "/seasons/dvd/{$seasonNumber}") !== false
                    )) {
                        if (strpos($imageUrl, 'posters/') !== false || 
                            strpos($imageUrl, 'poster/') !== false ||
                            strpos($imageUrl, 'poster_') !== false) {
                            $isRelevantImage = true;
                        }
                    }
                } else {
                    $isRelevantImage = (
                        (strpos($imageUrl, 'posters/') !== false &&
                         !preg_match('/seasons?[^\/]*\d+|s\d+[-_]|s0\d+/i', $imageUrl)) ||
                        (strpos($imageUrl, 'poster/') !== false &&
                         !preg_match('/seasons?[^\/]*\d+|s\d+[-_]|s0\d+/i', $imageUrl)) ||
                        (strpos($imageUrl, 'poster_') !== false &&
                         !preg_match('/seasons?[^\/]*\d+|s\d+[-_]|s0\d+/i', $imageUrl))
                    );
                }
            }
            
            if ($isRelevantImage && !in_array($imageUrl, $posters)) {
                $posters[] = $imageUrl;
            }
        }
    }
    
    return $posters;
}

// Function to find a movie ID by name (helper for collection search)
function findMovieByName($name)
{
    $searchResults = searchMovies($name);
    
    if (!empty($searchResults['results'])) {
        return $searchResults['results'][0]['id'];
    }
    
    return null;
}

// Poster formatting functions (same as original)
function formatTmdbPosterUrls($posterPath)
{
    global $tmdbPosterBaseUrl, $tmdbPosterSizes;
    
    if (empty($posterPath)) {
        return null;
    }
    
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

// Utility functions
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

function getPosterFilename($poster)
{
    if (!isset($poster['original'])) {
        return '';
    }
    
    $pathParts = explode('/', $poster['original']);
    return end($pathParts);
}

function getSeasonDetails($tvId, $seasonNumber)
{
    return makeTmdbRequest("/tv/{$tvId}/season/{$seasonNumber}", [
        'language' => 'en-US'
    ]);
}

// Main processing logic
$response = [
    'success' => true,
    'query' => '',
    'results' => []
];

// Get and validate parameters
$searchTerm = isset($_GET['q']) ? trim($_GET['q']) : '';
$mediaType = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : 'all';
$showSeasons = isset($_GET['show_seasons']) && ($_GET['show_seasons'] === 'true' || $_GET['show_seasons'] === '1');
$specificSeason = isset($_GET['season']) ? intval($_GET['season']) : null;
$includeAllPosters = isset($_GET['include_all_posters']) && ($_GET['include_all_posters'] === 'true' || $_GET['include_all_posters'] === '1');
$includeTvdb = isset($_GET['include_tvdb']) ? ($_GET['include_tvdb'] === 'true' || $_GET['include_tvdb'] === '1') : true;
$enableDebug = isset($_GET['debug']) && ($_GET['debug'] === 'true' || $_GET['debug'] === '1');

// Parse season number from query
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

// Validate inputs
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

// Search based on media type using TMDB
$searchResults = [];
if ($mediaType === 'movie') {
    $searchResults = searchMovies($searchTerm);
} elseif ($mediaType === 'tv') {
    $searchResults = searchTVShows($searchTerm);
} elseif ($mediaType === 'collection') {
    $searchResults = searchCollections($searchTerm);
} else { // 'all'
    $searchResults = searchMulti($searchTerm);
    
    // Also search collections separately
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

// Check if we got any results
if (empty($searchResults['results'])) {
    $response['success'] = true;
    $response['message'] = 'No results found matching the query';
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
}

// Separate arrays to keep track of results by type (same as original)
$movieResults = [];
$tvResults = [];
$collectionResults = [];

// Process and separate results by type
foreach ($searchResults['results'] as $result) {
    $type = $mediaType === 'all' ? (isset($result['media_type']) ? $result['media_type'] : $mediaType) : $mediaType;
    if ($type === 'person') {
        continue;
    }
    
    if ($type === 'movie') {
        $movieResults[] = $result;
    } elseif ($type === 'tv') {
        $tvResults[] = $result;
    } elseif ($type === 'collection') {
        $collectionResults[] = $result;
    }
}

// Pre-fetch all TMDB details for movies in batch
$movieDetailRequests = [];
foreach ($movieResults as $index => $result) {
    $movieDetailRequests["movie_details_{$index}"] = [
        'path' => "/movie/{$result['id']}",
        'params' => ['language' => 'en-US', 'append_to_response' => 'external_ids']
    ];
}

// Pre-fetch all TMDB movie images in batch
$movieImageRequests = [];
foreach ($movieResults as $index => $result) {
    $movieImageRequests["movie_images_{$index}"] = [
        'path' => "/movie/{$result['id']}/images",
        'params' => ['include_image_language' => 'en,null']
    ];
}

// Pre-fetch all TMDB details for TV shows in batch
$tvDetailRequests = [];
foreach ($tvResults as $index => $result) {
    $tvDetailRequests["tv_details_{$index}"] = [
        'path' => "/tv/{$result['id']}",
        'params' => ['language' => 'en-US', 'append_to_response' => 'external_ids']
    ];
}

// Pre-fetch all TMDB TV images in batch
$tvImageRequests = [];
foreach ($tvResults as $index => $result) {
    $tvImageRequests["tv_images_{$index}"] = [
        'path' => "/tv/{$result['id']}/images",
        'params' => ['include_image_language' => 'en,null']
    ];
}

// Pre-fetch all TMDB details for collections in batch
$collectionDetailRequests = [];
foreach ($collectionResults as $index => $result) {
    $collectionDetailRequests["collection_details_{$index}"] = [
        'path' => "/collection/{$result['id']}",
        'params' => ['language' => 'en-US']
    ];
}

// Pre-fetch all TMDB collection images in batch
$collectionImageRequests = [];
foreach ($collectionResults as $index => $result) {
    $collectionImageRequests["collection_images_{$index}"] = [
        'path' => "/collection/{$result['id']}/images",
        'params' => ['include_image_language' => 'en,null']
    ];
}

// Execute all TMDB requests in parallel
debugLog("Fetching TMDB data in parallel", [
    "movie_details" => count($movieDetailRequests),
    "movie_images" => count($movieImageRequests),
    "tv_details" => count($tvDetailRequests),
    "tv_images" => count($tvImageRequests),
    "collection_details" => count($collectionDetailRequests),
    "collection_images" => count($collectionImageRequests)
]);

$allTmdbRequests = array_merge(
    $movieDetailRequests,
    $movieImageRequests,
    $tvDetailRequests,
    $tvImageRequests,
    $collectionDetailRequests,
    $collectionImageRequests
);

$tmdbBatchResults = makeTmdbRequestBatch($allTmdbRequests);

// Pre-fetch all fanart.tv data in batch
$movieFanartRequests = [];
$tvFanartRequests = [];

foreach ($movieResults as $index => $result) {
    $movieFanartRequests["movie_{$index}"] = $result['id'];
}

foreach ($tvResults as $index => $result) {
    $detailKey = "tv_details_{$index}";
    if (isset($tmdbBatchResults[$detailKey]) && isset($tmdbBatchResults[$detailKey]['external_ids']['tvdb_id'])) {
        $tvFanartRequests["tv_{$index}"] = $tmdbBatchResults[$detailKey]['external_ids']['tvdb_id'];
    }
}

debugLog("Fetching fanart.tv data in parallel", [
    "movies" => count($movieFanartRequests),
    "tv_shows" => count($tvFanartRequests)
]);

$movieFanartResults = getMovieArtworkBatch($movieFanartRequests);
$tvFanartResults = getTVArtworkBatch($tvFanartRequests);

// Pre-fetch all TheTVDB data in batch
$tvdbItems = [];
if ($includeTvdb) {
    foreach ($movieResults as $index => $result) {
        $tvdbItems[] = [
            'title' => $result['title'],
            'type' => 'movie',
            'key' => "movie_{$index}"
        ];
    }
    
    foreach ($tvResults as $index => $result) {
        $tvdbItems[] = [
            'title' => $result['name'],
            'type' => 'tv',
            'key' => "tv_{$index}"
        ];
    }
    
    foreach ($collectionResults as $index => $result) {
        $collectionName = isset($result['name']) ? $result['name'] : $result['title'];
        $tvdbItems[] = [
            'title' => $collectionName,
            'type' => 'movie',
            'key' => "collection_{$index}"
        ];
    }
}

debugLog("Fetching TheTVDB data in parallel", ["count" => count($tvdbItems)]);
$tvdbBatchResults = $includeTvdb ? getTvdbPostersBatch($tvdbItems, $specificSeason) : [];

// Now process all movie results exactly like the original
foreach ($movieResults as $index => $result) {
    $movieDetails = $tmdbBatchResults["movie_details_{$index}"] ?? null;
    $movieImages = $tmdbBatchResults["movie_images_{$index}"] ?? null;
    
    if (!$movieDetails) continue;
    
    $tmdbId = $result['id'];
    $imdbId = isset($movieDetails['external_ids']['imdb_id']) ? $movieDetails['external_ids']['imdb_id'] : null;
    
    $fanartData = $movieFanartResults["movie_{$index}"] ?? [];
    
    // Create the base item with movie metadata
    $baseItem = [
        'id' => $result['id'],
        'type' => 'movie',
        'title' => $result['title'],
        'release_date' => isset($result['release_date']) ? $result['release_date'] : null,
    ];
    
    if ($imdbId) {
        $baseItem['imdb_id'] = $imdbId;
    }
    
    // First add fanart.tv posters (exact same logic as original)
    if (!empty($fanartData) && isset($fanartData['movieposter'])) {
        foreach ($fanartData['movieposter'] as $poster) {
            $item = $baseItem;
            $item['poster'] = formatFanartPosterUrls($poster['url']);
            $item['source'] = 'fanart.tv';
            $response['results'][] = $item;
        }
    }
    
    // Add TMDB poster as a separate result (exact same logic as original)
    if (!empty($result['poster_path'])) {
        $item = $baseItem;
        $item['poster'] = formatTmdbPosterUrls($result['poster_path']);
        $item['source'] = 'tmdb';
        $response['results'][] = $item;
    }
    
    // Get all posters from TMDB images endpoint (exact same logic as original)
    if (!empty($movieImages) && isset($movieImages['posters']) && !empty($movieImages['posters'])) {
        foreach ($movieImages['posters'] as $poster) {
            // Skip the main poster we already added
            if ($poster['file_path'] === $result['poster_path']) {
                continue;
            }
            
            $item = $baseItem;
            $item['poster'] = formatTmdbPosterUrls($poster['file_path']);
            $item['source'] = 'tmdb';
            $response['results'][] = $item;
        }
    }
    
    // Add TheTVDB posters if enabled (exact same logic as original)
    if ($includeTvdb) {
        $tvdbKey = "movie_{$index}";
        if (isset($tvdbBatchResults[$tvdbKey])) {
            foreach ($tvdbBatchResults[$tvdbKey] as $posterUrl) {
                $item = $baseItem;
                $item['poster'] = formatTvdbPosterUrls($posterUrl);
                $item['source'] = 'thetvdb';
                $response['results'][] = $item;
            }
        }
    }
}

// Process all TV show results exactly like the original
foreach ($tvResults as $index => $result) {
    $tvDetails = $tmdbBatchResults["tv_details_{$index}"] ?? null;
    $tvImages = $tmdbBatchResults["tv_images_{$index}"] ?? null;
    
    if (!$tvDetails) continue;
    
    $tvdbId = isset($tvDetails['external_ids']['tvdb_id']) ? $tvDetails['external_ids']['tvdb_id'] : null;
    $tmdbId = $result['id'];
    
    $fanartData = $tvFanartResults["tv_{$index}"] ?? [];
    
    $seenSeasonPosters = [];
    
    // Create the base item with TV show metadata
    $baseItem = [
        'id' => $result['id'],
        'type' => 'tv',
        'title' => $result['name'],
        'first_air_date' => isset($result['first_air_date']) ? $result['first_air_date'] : null,
    ];
    
    if ($tvdbId) {
        $baseItem['tvdb_id'] = $tvdbId;
    }
    
    // Helper function to add season info (exact same logic as original)
    $addSeasonInfo = function($item) use ($specificSeason, $tvDetails, &$seenSeasonPosters) {
        if ($specificSeason !== null) {
            $seasonData = null;
            $foundSeason = false;
            
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
                        
                        $foundSeason = true;
                        break;
                    }
                }
            }
            
            if ($foundSeason) {
                $item['season'] = $seasonData;
            } else {
                $item['season'] = null;
                $item['season_not_found'] = true;
            }
        }
        return $item;
    };
    
    // First add fanart.tv posters (exact same logic as original)
    if (!empty($fanartData) && isset($fanartData['tvposter'])) {
        foreach ($fanartData['tvposter'] as $poster) {
            $item = $baseItem;
            $item['poster'] = formatFanartPosterUrls($poster['url']);
            $item['source'] = 'fanart.tv';
            $item = $addSeasonInfo($item);
            $response['results'][] = $item;
        }
    }
    
    // Add TMDB poster (exact same logic as original)
    if (!empty($result['poster_path'])) {
        $item = $baseItem;
        $item['poster'] = formatTmdbPosterUrls($result['poster_path']);
        $item['source'] = 'tmdb';
        $item = $addSeasonInfo($item);
        $response['results'][] = $item;
    }
    
    // Get all posters from TMDB images endpoint (exact same logic as original)
    if (!empty($tvImages) && isset($tvImages['posters']) && !empty($tvImages['posters'])) {
        foreach ($tvImages['posters'] as $poster) {
            if ($poster['file_path'] === $result['poster_path']) {
                continue;
            }
            
            $item = $baseItem;
            $item['poster'] = formatTmdbPosterUrls($poster['file_path']);
            $item['source'] = 'tmdb';
            $item = $addSeasonInfo($item);
            $response['results'][] = $item;
        }
    }
    
    // If we're looking for a specific season and have fanart.tv season posters, add those (exact same logic as original)
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
    
    // Add TheTVDB posters if enabled (exact same logic as original)
    if ($includeTvdb) {
        $tvdbKey = "tv_{$index}";
        if (isset($tvdbBatchResults[$tvdbKey])) {
            foreach ($tvdbBatchResults[$tvdbKey] as $posterUrl) {
                $item = $baseItem;
                $item['poster'] = formatTvdbPosterUrls($posterUrl);
                $item['source'] = 'thetvdb';
                $item = $addSeasonInfo($item);
                $response['results'][] = $item;
            }
        }
    }
}

// Process all collection results exactly like the original
foreach ($collectionResults as $index => $result) {
    if (empty($result['poster_path'])) {
        continue;
    }
    
    $collectionDetails = $tmdbBatchResults["collection_details_{$index}"] ?? null;
    $collectionImages = $tmdbBatchResults["collection_images_{$index}"] ?? null;
    
    if (!$collectionDetails) continue;
    
    $collectionId = $result['id'];
    $collectionName = isset($result['name']) ? $result['name'] : $result['title'];
    
    $baseItem = [
        'id' => $collectionId,
        'type' => 'collection',
        'title' => $collectionName,
    ];
    
    // Add TMDB poster for the collection itself (exact same logic as original)
    if (!empty($result['poster_path'])) {
        $item = $baseItem;
        $item['poster'] = formatTmdbPosterUrls($result['poster_path']);
        $item['source'] = 'tmdb';
        $response['results'][] = $item;
    }
    
    // Get all posters from TMDB images endpoint for the collection (exact same logic as original)
    if (!empty($collectionImages) && isset($collectionImages['posters']) && !empty($collectionImages['posters'])) {
        foreach ($collectionImages['posters'] as $poster) {
            if ($poster['file_path'] === $result['poster_path']) {
                continue;
            }
            
            $item = $baseItem;
            $item['poster'] = formatTmdbPosterUrls($poster['file_path']);
            $item['source'] = 'tmdb';
            $response['results'][] = $item;
        }
    }
    
    // Try to find the collection on fanart.tv by searching for "{Collection Name} Collection" (exact same logic as original)
    $searchName = $collectionName;
    if (stripos($collectionName, 'Collection') === false) {
        $searchName .= ' Collection';
    }
    
    $collectionMovieId = findMovieByName($searchName);
    
    if ($collectionMovieId) {
        $fanartData = getMovieArtwork($collectionMovieId);
        
        if (!empty($fanartData) && isset($fanartData['movieposter'])) {
            foreach ($fanartData['movieposter'] as $poster) {
                $item = $baseItem;
                $item['poster'] = formatFanartPosterUrls($poster['url']);
                $item['source'] = 'fanart.tv';
                $response['results'][] = $item;
            }
        }
    }
    
    // If we didn't find collection-specific posters, get posters from the first movie in the collection (exact same logic as original)
    if (isset($collectionDetails['parts']) && !empty($collectionDetails['parts']) &&
        ($collectionMovieId === null || empty($fanartData) || !isset($fanartData['movieposter']))) {
        
        $firstMovie = $collectionDetails['parts'][0];
        $fanartData = getMovieArtwork($firstMovie['id']);
        
        if (!empty($fanartData) && isset($fanartData['movieposter'])) {
            foreach ($fanartData['movieposter'] as $poster) {
                $item = $baseItem;
                $item['poster'] = formatFanartPosterUrls($poster['url']);
                $item['source'] = 'fanart.tv';
                $response['results'][] = $item;
            }
        }
    }
    
    // Add TheTVDB posters if enabled (exact same logic as original)
    if ($includeTvdb) {
        $tvdbKey = "collection_{$index}";
        if (isset($tvdbBatchResults[$tvdbKey])) {
            foreach ($tvdbBatchResults[$tvdbKey] as $posterUrl) {
                $item = $baseItem;
                $item['poster'] = formatTvdbPosterUrls($posterUrl);
                $item['source'] = 'thetvdb';
                $response['results'][] = $item;
            }
        }
    }
}

// Filter out null results
$response['results'] = array_filter($response['results']);

// Set count in response
$response['count'] = count($response['results']);

debugLog("Processing completed", ["final_count" => $response['count']]);

// Add debug info if enabled
if ($enableDebug) {
    $response['debug'] = $debugLog;
}

// Optional response caching
$cacheDuration = isset($_GET['cache']) ? intval($_GET['cache']) : 0;
if ($cacheDuration > 0) {
    header('Cache-Control: public, max-age=' . $cacheDuration);
}

// Cleanup
if ($multiHandle !== null) {
    curl_multi_close($multiHandle);
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
