## MODIFIED Requirements

### Requirement: Server time endpoint

The system SHALL serve `GET /marquee/api/v2/time`, returning the server's current time as JSON with permissive CORS and without requiring client identification.

No client SHALL be required to call this endpoint in order to use the poster endpoint; it exists for diagnostics.

The `v1` time endpoint SHALL remain reachable at `GET /marquee/api/v1/time`.

#### Scenario: Time requested

- **WHEN** a client sends `GET /marquee/api/v2/time`
- **THEN** the system responds `200` with the current server time in epoch milliseconds and ISO-8601 form

#### Scenario: Time endpoint never called

- **WHEN** a client searches for posters without ever calling the time endpoint
- **THEN** the search succeeds

#### Scenario: Previous version still answers

- **WHEN** a client sends `GET /marquee/api/v1/time`
- **THEN** the system responds `200` exactly as it did before `v2` was deployed

### Requirement: Open reachability for self-hosted clients

The endpoint SHALL be reachable from any IP address, with no allowlist, denylist, or geographic restriction, and SHALL require no user-supplied credential, registration, signup, per-install key, or token exchange.

A client installed at a previously unseen address SHALL succeed on its first request when it sends valid client identification and nothing else.

This SHALL hold for every source the endpoint offers. A source that can only be served by asking the client to supply its own credential SHALL NOT be added; the requirement above takes precedence over adding a source.

#### Scenario: First request from an unknown address

- **WHEN** a request arrives from an IP address the server has never seen, carrying only the client identification header and no `key` parameter
- **THEN** the system serves it normally

#### Scenario: No credential is demanded

- **WHEN** a client that has never contacted the server searches for posters
- **THEN** it succeeds without having registered, obtained a key, or configured a secret

#### Scenario: Every source is served without a client credential

- **WHEN** a client issues a request that selects every available source
- **THEN** all of them are queried on the strength of the deployment's own configuration, and none requires a credential from the client
