<?php
// Cache and counter storage.
//
// APCu when the extension is loaded, files under the system temp directory
// otherwise. The filesystem path is the assumed one, not a degraded fallback.
// Nothing here requires configuration or a connection string.
//
// Every operation fails soft: a read failure is a miss, a write failure is
// ignored, and a counter failure returns null so the caller serves the request.
// The store is never load-bearing.
//
// The namespace is derived from the endpoint version, so v1 and v2 never share an
// entry. They otherwise would: the artwork cache key is built from the resolved
// work and the shaping parameters, and `sources=tmdb` produces a byte-identical key
// in both versions. A shared entry would serve a v2 payload — whose `providers` map
// carries a `tvmaze` key — to a v1 client, which is exactly the contract drift
// freezing v1 exists to prevent.

if (!defined('MARQUEE_API_V2')) {
    http_response_code(404);
    exit;
}

/**
 * Key prefix for the APCu backend, and the discriminator that keeps this version's
 * entries separate from v1's. The filesystem backend gets the same separation from
 * its own directory name.
 */
function marqueeStoreNamespace(): string
{
    return 'marquee:' . MARQUEE_ENDPOINT_VERSION . ':';
}

function marqueeStoreUsesApcu(): bool
{
    static $available = null;
    if ($available === null) {
        $available = function_exists('apcu_fetch')
            && function_exists('apcu_store')
            && ini_get('apc.enabled') !== '0';
    }
    return $available;
}

function marqueeStoreDir(): ?string
{
    static $dir = null;
    static $checked = false;

    if ($checked) {
        return $dir;
    }
    $checked = true;

    $candidate = rtrim(sys_get_temp_dir(), '/') . '/marquee-api-' . MARQUEE_ENDPOINT_VERSION;
    if (!is_dir($candidate) && !@mkdir($candidate, 0700, true) && !is_dir($candidate)) {
        return null;
    }
    if (!is_writable($candidate)) {
        return null;
    }

    $dir = $candidate;
    return $dir;
}

function marqueeStorePath(string $key): ?string
{
    $dir = marqueeStoreDir();
    if ($dir === null) {
        return null;
    }
    return $dir . '/' . sha1($key) . '.json';
}

/** @return mixed|null null on miss, on expiry, or on any failure */
function marqueeStoreGet(string $key)
{
    if (marqueeStoreUsesApcu()) {
        $ok = false;
        $value = apcu_fetch(marqueeStoreNamespace() . $key, $ok);
        return $ok ? $value : null;
    }

    $path = marqueeStorePath($key);
    if ($path === null || !is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }

    $entry = json_decode($raw, true);
    if (!is_array($entry) || !isset($entry['expires'])) {
        return null;
    }
    if ($entry['expires'] < time()) {
        @unlink($path);
        return null;
    }

    return $entry['value'] ?? null;
}

/** @param mixed $value */
function marqueeStoreSet(string $key, $value, int $ttl): bool
{
    if (marqueeStoreUsesApcu()) {
        return (bool) apcu_store(marqueeStoreNamespace() . $key, $value, $ttl);
    }

    $path = marqueeStorePath($key);
    if ($path === null) {
        return false;
    }

    $payload = json_encode(['expires' => time() + $ttl, 'value' => $value]);
    if ($payload === false) {
        return false;
    }

    // Write to a unique temp file and rename, so a concurrent reader never sees
    // a half-written entry.
    $tmp = $path . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    marqueeStoreSweep();
    return true;
}

/**
 * Increment a counter within a fixed window.
 *
 * @return int|null the new count, or null when the store is unavailable — the
 *                  caller must serve the request rather than reject it.
 */
function marqueeStoreIncrement(string $key, int $ttl): ?int
{
    if (marqueeStoreUsesApcu()) {
        $ok = false;
        $count = apcu_inc(marqueeStoreNamespace() . $key, 1, $ok, $ttl);
        return $ok ? (int) $count : null;
    }

    $path = marqueeStorePath($key);
    if ($path === null) {
        return null;
    }

    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        return null;
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return null;
    }

    $raw = stream_get_contents($handle);
    $entry = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

    $count = 1;
    if (is_array($entry) && isset($entry['expires'], $entry['value']) && $entry['expires'] >= time()) {
        $count = (int) $entry['value'] + 1;
    }

    $payload = json_encode(['expires' => time() + $ttl, 'value' => $count]);
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, $payload === false ? '' : $payload);
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return $count;
}

/**
 * Opportunistically drop expired entries. Runs rarely, costs nothing when it
 * doesn't, and keeps the temp directory from growing without bound.
 */
function marqueeStoreSweep(): void
{
    if (random_int(1, 200) !== 1) {
        return;
    }

    $dir = marqueeStoreDir();
    if ($dir === null) {
        return;
    }

    $files = @glob($dir . '/*.json');
    if (!is_array($files)) {
        return;
    }

    $now = time();
    foreach ($files as $file) {
        // Anything older than the longest TTL is expired regardless of contents.
        $mtime = @filemtime($file);
        if ($mtime !== false && $mtime < $now - CACHE_TTL_SECONDS) {
            @unlink($file);
        }
    }
}
