## ADDED Requirements

### Requirement: Open reachability for self-hosted clients

The endpoint SHALL be reachable from any IP address, with no allowlist, denylist, or geographic restriction, and SHALL require no user-supplied credential, registration, signup, per-install key, or token exchange.

A client installed at a previously unseen address SHALL succeed on its first request when it sends valid client identification and nothing else.

#### Scenario: First request from an unknown address

- **WHEN** a request arrives from an IP address the server has never seen, carrying only the client identification header and no `key` parameter
- **THEN** the system serves it normally

#### Scenario: No credential is demanded

- **WHEN** a client that has never contacted the server searches for posters
- **THEN** it succeeds without having registered, obtained a key, or configured a secret

### Requirement: Client identification header

The system SHALL require an `X-Client-Info` request header, whose value is a base64-encoded JSON object containing a `name` naming the client application and a `ts` millisecond epoch timestamp. The object MAY carry a `version` string, which SHALL be recorded for logging and SHALL NOT affect whether the request is served.

A request SHALL be served when the header decodes to valid JSON and `name` is a recognised client name. A request SHALL be rejected with `401` and `code: "unauthorized"` when the header is absent, is not valid base64, does not decode to JSON, or carries an unrecognised `name`.

This header identifies the client for logging. It SHALL NOT be treated as a secret, and the system SHALL NOT implement request signing, nonces, or token exchange around it.

#### Scenario: Valid identification

- **WHEN** a request carries an `X-Client-Info` header naming a recognised client
- **THEN** the request is served

#### Scenario: Version absent

- **WHEN** the header carries a recognised `name` and a valid `ts` but no `version`
- **THEN** the request is served and the version is logged as unknown

#### Scenario: Header absent

- **WHEN** a request carries no `X-Client-Info` header and no `key` parameter
- **THEN** the system responds `401` with `code: "unauthorized"`, not a server error and not an empty success

#### Scenario: Header malformed

- **WHEN** the header value is not decodable base64 JSON
- **THEN** the system responds `401` with `code: "unauthorized"`

#### Scenario: Unrecognised client name

- **WHEN** the header decodes correctly but `name` is not a recognised client
- **THEN** the system responds `401` with `code: "unauthorized"`

### Requirement: Clock-skew tolerance

The system SHALL accept an `X-Client-Info` timestamp within ±24 hours of server time, including timestamps in the future, so that clients on hosts with drifting or unset clocks are served.

A client SHALL NOT be required to synchronise its clock, nor to call any time endpoint, in order to be served.

#### Scenario: Client clock is behind

- **WHEN** a request carries a timestamp two hours in the past
- **THEN** the request is served

#### Scenario: Client clock is ahead

- **WHEN** a request carries a timestamp two hours in the future
- **THEN** the request is served

#### Scenario: Timestamp far outside tolerance

- **WHEN** a request carries a timestamp more than 24 hours from server time
- **THEN** the system responds `401` with `code: "unauthorized"`

#### Scenario: Timestamp missing or non-numeric

- **WHEN** the header decodes with a recognised `name` but a missing or non-numeric `ts`
- **THEN** the system responds `401` with `code: "unauthorized"`

### Requirement: Optional key parameter

The system SHALL accept an optional `key` query parameter matching `POSTERIA_API_KEY` as an alternative to the `X-Client-Info` header. Supplying it SHALL never be required, and its absence SHALL never be a reason to reject a request that carries valid client identification.

#### Scenario: Key supplied instead of the header

- **WHEN** a request carries a correct `key` parameter and no `X-Client-Info` header
- **THEN** the request is served

#### Scenario: Key absent

- **WHEN** a request carries valid client identification and no `key` parameter
- **THEN** the request is served

#### Scenario: Incorrect key with valid identification

- **WHEN** a request carries valid client identification and an incorrect `key`
- **THEN** the request is served on the strength of the header

### Requirement: Permissive CORS

The system SHALL send `Access-Control-Allow-Origin: *`, SHALL advertise the `GET` method and the `X-Client-Info` request header in its CORS headers, and SHALL answer preflight `OPTIONS` requests with `204` without requiring client identification.

#### Scenario: Browser preflight

- **WHEN** a browser sends an `OPTIONS` preflight for the poster endpoint
- **THEN** the system responds `204` with permissive CORS headers and does not require an `X-Client-Info` header

#### Scenario: Cross-origin GET

- **WHEN** a request arrives from any origin
- **THEN** the response carries `Access-Control-Allow-Origin: *`

### Requirement: Rate limiting as a throttle

The system SHALL rate limit per client IP address at 60 requests per minute and 3,000 requests per hour, sized on the understanding that one address may represent an entire household or a shared server.

A previously unseen address's first request SHALL always be served; the rate limit SHALL never act as an access gate. Counters SHALL be held in the filesystem or APCu, requiring no configuration and no new environment variable, and a counter store failure SHALL cause the request to be served rather than rejected.

#### Scenario: Sustained realistic use

- **WHEN** one address issues 50 searches within a minute
- **THEN** every request is served

#### Scenario: Limit exceeded

- **WHEN** an address exceeds the per-minute limit
- **THEN** the system responds `429` with `code: "rate_limited"` and a `retry_after` value in seconds

#### Scenario: First request from a new address

- **WHEN** an address with no recorded history issues its first request
- **THEN** it is served regardless of the rate limiter's state

#### Scenario: Counter store unavailable

- **WHEN** the rate-limit counter store cannot be read or written
- **THEN** the request is served rather than rejected

### Requirement: Request logging by client

The system SHALL log each request with the client name and version taken from `X-Client-Info`, the request parameters, the resolved work, and the per-source outcomes, so that accuracy can be assessed per client version.

Logging SHALL NOT record any user-supplied secret, and a logging failure SHALL NOT fail the request.

#### Scenario: Search is logged

- **WHEN** a search request is served
- **THEN** a log entry records the client name and version, the query parameters, the resolved work, and each source's outcome

#### Scenario: Logging unavailable

- **WHEN** the log destination cannot be written
- **THEN** the request is served normally

### Requirement: Server time endpoint

The system SHALL serve `GET /marquee/api/v1/time`, returning the server's current time as JSON with permissive CORS and without requiring client identification.

No client SHALL be required to call this endpoint in order to use the poster endpoint; it exists for diagnostics.

#### Scenario: Time requested

- **WHEN** a client sends `GET /marquee/api/v1/time`
- **THEN** the system responds `200` with the current server time in epoch milliseconds and ISO-8601 form

#### Scenario: Time endpoint never called

- **WHEN** a client searches for posters without ever calling the time endpoint
- **THEN** the search succeeds
