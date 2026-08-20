<?php
// Client identification, rate limiting and request logging.
//
// The access model is fixed by Marquee being self-hosted: its users install it on
// home servers, NASes and VPSes at addresses nobody can enumerate, and they must
// never have to register or configure a secret to make poster search work. So the
// endpoint is open — any IP, no signup, no per-install key.
//
// X-Client-Info identifies the client for logging. It is not a secret: the name is
// a fixed string in open-source client code and the timestamp is just the current
// time, so anyone can construct one. That is understood and accepted, which is why
// there is no signing, no nonce and no token exchange here.

if (!defined('MARQUEE_API_V2')) {
    http_response_code(404);
    exit;
}

function marqueeRequestHeader(string $name): ?string
{
    // getallheaders() exists under the built-in server, but fall back to $_SERVER
    // so this never depends on the SAPI.
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return is_string($value) ? $value : null;
            }
        }
    }

    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return isset($_SERVER[$serverKey]) ? (string) $_SERVER[$serverKey] : null;
}

/**
 * Decode the client identification header.
 *
 * @return array|null ['name' => string, 'version' => ?string, 'ts' => int] or null
 *                    when the header is absent or unusable
 */
function marqueeParseClientInfo(?string $headerValue): ?array
{
    if ($headerValue === null || trim($headerValue) === '') {
        return null;
    }

    $decoded = base64_decode(trim($headerValue), true);
    if ($decoded === false) {
        return null;
    }

    $data = json_decode($decoded, true);
    if (!is_array($data)) {
        return null;
    }

    if (!isset($data['name']) || !is_string($data['name'])) {
        return null;
    }

    if (!isset($data['ts']) || !is_numeric($data['ts'])) {
        return null;
    }

    $version = null;
    if (isset($data['version']) && (is_string($data['version']) || is_numeric($data['version']))) {
        $version = (string) $data['version'];
    }

    return [
        'name' => $data['name'],
        'version' => $version,
        'ts' => (int) $data['ts'],
    ];
}

/**
 * Validate a decoded header.
 *
 * The timestamp tolerance is wide and symmetric — future timestamps included —
 * because a narrow window silently locks out every self-hoster whose clock has
 * drifted: Raspberry Pis without a battery-backed RTC, NAS Docker hosts, VMs
 * resumed from suspend. Since the header is identification rather than
 * authentication, a narrow window buys nothing and costs real users.
 */
function marqueeClientInfoIsValid(array $clientInfo): bool
{
    if (!in_array($clientInfo['name'], marqueeAcceptedClientNames(), true)) {
        return false;
    }

    $now = (int) round(microtime(true) * 1000);
    return abs($clientInfo['ts'] - $now) <= CLIENT_TS_TOLERANCE_MS;
}

/**
 * Identify the caller, or exit 401.
 *
 * Two independent paths, either of which is sufficient: a valid X-Client-Info
 * header, or the optional `key` parameter. The key is never required, and an
 * incorrect key never rejects a request that carries valid client identification.
 *
 * @return array ['name' => string, 'version' => ?string]
 */
function marqueeIdentifyClient(array $credentials): array
{
    $clientInfo = marqueeParseClientInfo(marqueeRequestHeader(CLIENT_HEADER_NAME));

    if ($clientInfo !== null && marqueeClientInfoIsValid($clientInfo)) {
        return ['name' => $clientInfo['name'], 'version' => $clientInfo['version']];
    }

    $suppliedKey = isset($_GET['key']) && is_string($_GET['key']) ? $_GET['key'] : null;
    if ($suppliedKey !== null && $credentials['access'] !== null
        && hash_equals($credentials['access'], $suppliedKey)) {
        return ['name' => 'key', 'version' => null];
    }

    marqueeSendFailure(
        'unauthorized',
        'A valid ' . CLIENT_HEADER_NAME . ' header is required. Send base64-encoded '
        . '{"name":"<client>","version":"<version>","ts":<epoch ms>}.'
    );
}

function marqueeClientIp(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    return $remote !== '' ? (string) $remote : 'unknown';
}

/**
 * Throttle, not a gate.
 *
 * The realistic cause of a quota spike is a buggy client in a retry loop, not an
 * attacker, and one IP is one household or one server rather than one person — a
 * family sharing a Plex install, or several Marquee users behind CGNAT, all appear
 * as a single address. So the limits are generous, an unseen address always
 * succeeds, and a store failure serves the request rather than rejecting it.
 */
function marqueeEnforceRateLimit(string $ip): void
{
    $now = time();

    $windows = [
        ['key' => 'rate:m:' . $ip . ':' . intdiv($now, 60), 'ttl' => 60, 'max' => RATE_LIMIT_PER_MINUTE, 'reset' => 60 - ($now % 60)],
        ['key' => 'rate:h:' . $ip . ':' . intdiv($now, 3600), 'ttl' => 3600, 'max' => RATE_LIMIT_PER_HOUR, 'reset' => 3600 - ($now % 3600)],
    ];

    foreach ($windows as $window) {
        $count = marqueeStoreIncrement($window['key'], $window['ttl']);
        if ($count === null) {
            continue; // Store unavailable — serve the request.
        }
        if ($count > $window['max']) {
            header('Retry-After: ' . $window['reset']);
            marqueeSendFailure(
                'rate_limited',
                'Too many requests. Retry in ' . $window['reset'] . ' seconds.',
                ['retry_after' => $window['reset']]
            );
        }
    }
}

/**
 * Log by client name and version — the signal wanted while tuning accuracy.
 *
 * Never records the `key` value, and never fails the request.
 */
function marqueeLogRequest(array $entry): void
{
    try {
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES);
        if ($line !== false) {
            error_log('marquee-api-' . MARQUEE_ENDPOINT_VERSION . ' ' . $line);
        }
    } catch (Throwable $e) {
        // Logging is never load-bearing.
    }
}
