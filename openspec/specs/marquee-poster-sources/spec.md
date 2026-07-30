# marquee-poster-sources Specification

## Purpose
TBD - created by archiving change add-marquee-poster-api. Update Purpose after archive.
## Requirements
### Requirement: Supported poster sources

The system SHALL gather artwork from TMDB, fanart.tv and TheTVDB, identified in responses by the source names `tmdb`, `fanart.tv` and `thetvdb`.

Every poster returned SHALL be attributed to the source that supplied that specific image. A poster's `source` SHALL NOT be set from the source that supplied the work's metadata when a different source supplied the image.

The system SHALL NOT scrape artwork out of any provider's HTML pages. Artwork SHALL be obtained only through a provider API whose response identifies the work being requested.

The system SHALL NOT locate a provider record by a title-derived slug or by any other string match on the title. A provider record SHALL be located only by an identifier: one the system already holds, or one obtained from a remote-id lookup constrained to the requested record type.

#### Scenario: Mixed-source response

- **WHEN** artwork for a resolved work comes from more than one source
- **THEN** each poster names the source that supplied that image

#### Scenario: Source without an API credential

- **WHEN** a source cannot be queried through an API because the deployment holds no credential for it
- **THEN** the system omits that source entirely rather than deriving its artwork by other means

#### Scenario: Provider record located by identifier

- **WHEN** artwork is requested from a provider that indexes works by its own identifier
- **THEN** the system resolves that identifier from an identifier it already holds, and returns no artwork at all if it cannot

#### Scenario: Remote-id lookup returns several record types

- **WHEN** a remote-id lookup returns more than one record because the identifier collides across providers
- **THEN** the system selects only the record matching the requested type and ignores the rest

### Requirement: Source selection

The system SHALL query all available sources by default. When the `sources` parameter is supplied, the system SHALL query only the named sources, using the tokens `tmdb`, `fanart` and `tvdb`.

An unrecognised source token SHALL be ignored rather than rejected. A request whose `sources` list names no recognised source SHALL be treated as `invalid_request`.

#### Scenario: Subset requested

- **WHEN** a request supplies `sources=tmdb`
- **THEN** the system queries only TMDB and reports fanart.tv and TheTVDB as `skipped`

#### Scenario: Unrecognised token

- **WHEN** a request supplies `sources=tmdb,nosuchsource`
- **THEN** the system queries TMDB, ignores the unknown token, and reports the unqueried sources as `skipped`

#### Scenario: Token for a source that no longer exists

- **WHEN** a request supplies `sources=tmdb,mediux`
- **THEN** the system queries TMDB, ignores `mediux`, and the response contains no `mediux.pro` entry in `providers`

### Requirement: Identifier resolution across sources

The system SHALL resolve the external identifiers a source needs before querying it. Specifically it SHALL resolve, from TMDB, the work's TVDB identifier for shows and seasons — fanart.tv's television endpoint and TheTVDB's series endpoint are both keyed on it — and the work's IMDb identifier for films, which is how TheTVDB's film records are located.

Identifiers that cannot be resolved SHALL be reported as `null` in `match`, and the sources depending on them SHALL be reported as `no_data` rather than `error`.

#### Scenario: Show artwork from fanart.tv

- **WHEN** a `type=show` or `type=season` request resolves a show whose TVDB identifier is available
- **THEN** the system queries fanart.tv using that identifier and includes any artwork it returns

#### Scenario: Identifier unavailable

- **WHEN** a resolved show has no TVDB identifier
- **THEN** `match.tvdb_id` is `null` and fanart.tv is reported as `no_data`

#### Scenario: Film without an IMDb identifier

- **WHEN** a resolved film has no IMDb identifier
- **THEN** TheTVDB is reported as `no_data` and the system does not attempt to find the record by title

### Requirement: Sources are queried in parallel

The system SHALL issue the upstream requests for a single search concurrently rather than serially, and SHALL apply a bounded per-request timeout so that one slow source cannot extend the response indefinitely.

A source that exceeds its timeout SHALL be treated as failed for that request and reported as `error`.

#### Scenario: One slow source

- **WHEN** one source does not respond within its timeout while others succeed
- **THEN** the system returns the artwork it did retrieve and marks the slow source as `error`

### Requirement: Provider outcome map

Every response, successful or partial, SHALL carry a `providers` map whose keys are the source names and whose values are drawn from exactly this vocabulary:

- `ok` — the source was queried and returned artwork
- `no_data` — the source was queried successfully and has no artwork for this work, or the identifier it requires was unavailable
- `error` — the source was queried and failed, timed out, or returned an unusable response
- `skipped` — the source contributed nothing because the deployment cannot use it: it was excluded by `sources`, holds no credential, or holds a credential the source rejects

A source that rejects the deployment's credential SHALL be reported as `skipped` rather than `error`. A rejected credential fails identically on every request until it is replaced, so reporting it as an outage would mark every response `partial` and, since partial responses are not cached, would disable caching endpoint-wide. The rejection SHALL be logged so it remains diagnosable.

A source failure SHALL NOT be silently absorbed: an unreachable source SHALL always be distinguishable from a source that genuinely holds no artwork.

#### Scenario: All sources succeed

- **WHEN** every queried source returns artwork
- **THEN** each is reported as `ok` and the response carries no `partial` code

#### Scenario: Failure distinguishable from emptiness

- **WHEN** one source times out and another returns no artwork for the work
- **THEN** the first is reported as `error` and the second as `no_data`

#### Scenario: Credential absent

- **WHEN** a source's credential is unset in the deployment
- **THEN** that source is reported as `skipped` and the request otherwise succeeds

#### Scenario: Credential rejected

- **WHEN** a source answers a request with 401 or 403
- **THEN** that source is reported as `skipped`, the rejection is logged, the response is not marked `partial`, and the response remains cacheable

### Requirement: Configuration is an explicit, enumerated set

The system SHALL read exactly four environment variables — `TMDB_API_KEY`, `FANART_API_KEY`, `TVDB_API_KEY` and `POSTERIA_API_KEY` — and SHALL NOT read any other. Three are pre-existing; `TVDB_API_KEY` is the single addition, made by explicit owner decision rather than unilaterally. `MEDIUX_API_KEY` exists in the deployment but SHALL NOT be read, because Mediux is not a source.

All provider base URLs, image size mappings, artwork type identifiers, timeouts, limits and tolerances SHALL be constants in code rather than configuration.

The system SHALL start and serve requests when any of these variables is unset, degrading by marking the affected sources `skipped`. In particular it SHALL serve correctly before `TVDB_API_KEY` is configured, so that deploying the code and setting the variable need not be sequenced.

#### Scenario: Deployed before the new variable is set

- **WHEN** the endpoint is deployed into an environment where `TVDB_API_KEY` is not yet set
- **THEN** it serves requests normally, reports TheTVDB as `skipped`, does not mark the response `partial`, and the response remains cacheable

#### Scenario: New variable rejected

- **WHEN** `TVDB_API_KEY` is set to a value the provider rejects
- **THEN** TheTVDB is reported as `skipped`, the rejection is logged, and every other source is unaffected

#### Scenario: Partial credentials

- **WHEN** `TMDB_API_KEY` is set but `FANART_API_KEY` is unset
- **THEN** the system serves TMDB artwork and reports fanart.tv as `skipped`

### Requirement: A source is either functional or absent

The system SHALL NOT present a source that cannot return artwork in this deployment. A source that is advertised in the `sources` vocabulary or the `providers` map SHALL be one the system can actually query.

Mediux SHALL NOT be a source of this endpoint: its former host is unreachable and the deployment's `MEDIUX_API_KEY` is rejected by its current host, so it could only ever be advertised and never contribute. Accordingly the system SHALL NOT read `MEDIUX_API_KEY`, SHALL NOT accept `mediux` as a `sources` token, and SHALL NOT emit a `mediux.pro` entry in `providers` or as a poster `source`.

#### Scenario: Mediux is absent from the contract

- **WHEN** any request succeeds
- **THEN** `providers` contains no `mediux.pro` key and no poster carries `source: "mediux.pro"`

#### Scenario: Mediux requested explicitly

- **WHEN** a request supplies `sources=mediux` and no other recognised token
- **THEN** the system responds `400` with `code: "invalid_request"`

### Requirement: The legacy endpoint is unaffected

The new endpoint SHALL be self-contained: it SHALL NOT include, require, or otherwise depend on any file under `api/`, and no file under `api/` SHALL be modified in order to serve it. Provider code needed by both endpoints SHALL be duplicated rather than shared.

#### Scenario: Legacy endpoint unchanged

- **WHEN** the new endpoint is deployed
- **THEN** `GET /api/fetch/posters` behaves exactly as it did before and no file under `api/` has been modified

#### Scenario: New endpoint is self-contained

- **WHEN** the new endpoint serves a request
- **THEN** it loads only files under its own tree

