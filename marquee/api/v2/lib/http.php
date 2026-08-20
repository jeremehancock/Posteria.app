<?php
// Parallel HTTP client.
//
// Adapted from the curl_multi wrapper in api/fetch/posters/index.php, which is a
// sound design. Two changes: a bounded connect and total timeout so one slow
// provider cannot extend the response, and per-request error capture so a caller
// can tell a timeout from an empty result instead of testing a bare success flag.

if (!defined('MARQUEE_API_V2')) {
    http_response_code(404);
    exit;
}

class MarqueeHttpClient
{
    private static ?MarqueeHttpClient $instance = null;
    private $multiHandle;

    private function __construct()
    {
        $this->multiHandle = curl_multi_init();
        curl_multi_setopt($this->multiHandle, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
        curl_multi_setopt($this->multiHandle, CURLMOPT_MAX_TOTAL_CONNECTIONS, 10);
    }

    public static function getInstance(): MarqueeHttpClient
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Issue every request concurrently.
     *
     * @param array $requests key => ['url' => string, 'headers' => string[]]
     * @return array key => [
     *     'ok'        => bool,    // 2xx with a usable body
     *     'status'    => int,     // 0 when the request never completed
     *     'body'      => ?string,
     *     'json'      => ?array,  // decoded body, null when not valid JSON
     *     'error'     => ?string, // transport error, or a description of the failure
     *     'timed_out' => bool,
     * ]
     */
    public function fetchAll(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $handles = [];
        foreach ($requests as $key => $request) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $request['url'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => HTTP_CONNECT_TIMEOUT_SECONDS,
                CURLOPT_TIMEOUT => HTTP_TIMEOUT_SECONDS,
                CURLOPT_USERAGENT => MARQUEE_USER_AGENT,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
            ]);

            if (!empty($request['headers'])) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $request['headers']);
            }

            // Only TheTVDB's token exchange needs this; everything else is a GET.
            if (isset($request['post_json'])) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $request['post_json']);
            }

            curl_multi_add_handle($this->multiHandle, $ch);
            $handles[$key] = $ch;
        }

        $running = null;
        do {
            $status = curl_multi_exec($this->multiHandle, $running);
            if ($running > 0) {
                curl_multi_select($this->multiHandle, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $key => $ch) {
            $body = curl_multi_getcontent($ch);
            $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            $transportError = $errno !== 0 ? curl_error($ch) : null;

            $json = null;
            if (is_string($body) && $body !== '') {
                $decoded = json_decode($body, true);
                if (is_array($decoded)) {
                    $json = $decoded;
                }
            }

            $ok = $transportError === null && $httpStatus >= 200 && $httpStatus < 300;

            $error = $transportError;
            if ($error === null && !$ok) {
                $error = "HTTP {$httpStatus}";
            } elseif ($ok && $json === null) {
                // A 2xx that isn't JSON is not usable to any caller here.
                $ok = false;
                $error = 'response was not JSON';
            }

            $results[$key] = [
                'ok' => $ok,
                'status' => $httpStatus,
                'body' => is_string($body) ? $body : null,
                'json' => $json,
                'error' => $error,
                'timed_out' => in_array($errno, [CURLE_OPERATION_TIMEOUTED, CURLE_COULDNT_CONNECT], true),
            ];

            curl_multi_remove_handle($this->multiHandle, $ch);
            curl_close($ch);
        }

        return $results;
    }

    /** Convenience wrapper for a single request. */
    public function fetch(string $url, array $headers = []): array
    {
        $results = $this->fetchAll(['one' => ['url' => $url, 'headers' => $headers]]);
        return $results['one'];
    }

    /** Convenience wrapper for a single JSON POST. */
    public function postJson(string $url, array $body, array $headers = []): array
    {
        $results = $this->fetchAll(['one' => [
            'url' => $url,
            'headers' => array_merge(['Content-Type: application/json'], $headers),
            'post_json' => json_encode($body),
        ]]);
        return $results['one'];
    }

    public function __destruct()
    {
        if ($this->multiHandle) {
            curl_multi_close($this->multiHandle);
        }
    }
}
