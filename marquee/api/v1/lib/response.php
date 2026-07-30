<?php
// Response envelopes.
//
// Every exit from this endpoint goes through one of these two functions, so the
// shape and the status mapping are defined in exactly one place. A failure is
// always machine-readable: the client never has to parse a human string, and a
// resolved-but-artless item is never reported as a failure.

if (!defined('MARQUEE_API_V1')) {
    http_response_code(404);
    exit;
}

const ERROR_STATUS = [
    'invalid_request' => 400,
    'unauthorized' => 401,
    'no_match' => 404,
    'method_not_allowed' => 405,
    'rate_limited' => 429,
    'upstream_unavailable' => 503,
];

function marqueeSendCorsHeaders(): void
{
    // Origins are unknowable — Marquee is self-hosted — and the data served is
    // public poster art, so there is nothing for a same-origin policy to protect.
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: ' . CLIENT_HEADER_NAME . ', Content-Type');
    header('Access-Control-Max-Age: 86400');
}

function marqueeJsonEncode(array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json === false ? '{"success":false,"code":"upstream_unavailable","error":"Response could not be encoded."}' : $json;
}

/**
 * Emit a success response and exit.
 *
 * `code` is present only on a partial response and does not indicate failure —
 * callers branch on `success`.
 */
function marqueeSendSuccess(array $payload): void
{
    http_response_code(200);
    header('Content-Type: application/json');
    echo marqueeJsonEncode($payload);
    exit;
}

/**
 * Emit a failure response and exit.
 *
 * @param string $code  one of ERROR_STATUS
 * @param string $error human-readable detail
 * @param array  $extra additional top-level fields (e.g. retry_after)
 */
function marqueeSendFailure(string $code, string $error, array $extra = []): void
{
    $status = ERROR_STATUS[$code] ?? 500;

    http_response_code($status);
    header('Content-Type: application/json');
    echo marqueeJsonEncode(array_merge([
        'success' => false,
        'code' => $code,
        'error' => $error,
    ], $extra));
    exit;
}
