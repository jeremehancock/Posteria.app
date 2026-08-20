## MODIFIED Requirements

### Requirement: Supported poster sources

The system SHALL gather artwork from TMDB, fanart.tv, TheTVDB and TVmaze, identified in responses by the source names `tmdb`, `fanart.tv`, `thetvdb` and `tvmaze`.

Every poster returned SHALL be attributed to the source that supplied that specific image. A poster's `source` SHALL NOT be set from the source that supplied the work's metadata when a different source supplied the image.

The system SHALL NOT scrape artwork out of any provider's HTML pages. Artwork SHALL be obtained only through a provider API whose response identifies the work being requested.

The system SHALL NOT locate a provider record by a title-derived slug or by any other string match on the title. A provider record SHALL be located only by an identifier: one the system already holds, or one obtained from a remote-id lookup constrained to the requested record type.

A source that requires no credential SHALL be queried whenever it is selected, and SHALL NOT be reported as `skipped` for want of one.

#### Scenario: Mixed-source response

- **WHEN** artwork for a resolved work comes from more than one source
- **THEN** each poster names the source that supplied that image

#### Scenario: Source without an API credential

- **WHEN** a source that requires a credential cannot be queried because the deployment holds none for it
- **THEN** the system omits that source entirely rather than deriving its artwork by other means

#### Scenario: Credential-free source is always available

- **WHEN** a source requires no credential and the request does not exclude it
- **THEN** the system queries it regardless of which credentials the deployment holds, and never reports it as `skipped` on credential grounds

#### Scenario: Provider record located by identifier

- **WHEN** artwork is requested from a provider that indexes works by its own identifier
- **THEN** the system resolves that identifier from an identifier it already holds, and returns no artwork at all if it cannot

#### Scenario: Remote-id lookup returns several record types

- **WHEN** a remote-id lookup returns more than one record because the identifier collides across providers
- **THEN** the system selects only the record matching the requested type and ignores the rest

#### Scenario: Television-only source is not asked by title

- **WHEN** a `type=show` request resolves a work and TVmaze is selected
- **THEN** the system locates the TVmaze record by identifier and does not issue a title search against TVmaze under any circumstances

### Requirement: Source selection

The system SHALL query all available sources by default. When the `sources` parameter is supplied, the system SHALL query only the named sources, using the tokens `tmdb`, `fanart`, `tvdb` and `tvmaze`.

An unrecognised source token SHALL be ignored rather than rejected. A request whose `sources` list names no recognised source SHALL be treated as `invalid_request`.

#### Scenario: Subset requested

- **WHEN** a request supplies `sources=tmdb`
- **THEN** the system queries only TMDB and reports fanart.tv, TheTVDB and TVmaze as `skipped`

#### Scenario: New source requested alone

- **WHEN** a request supplies `sources=tvmaze` with `type=show`
- **THEN** the system queries only TVmaze and reports `tmdb`, `fanart.tv` and `thetvdb` as `skipped`

#### Scenario: Unrecognised token

- **WHEN** a request supplies `sources=tmdb,nosuchsource`
- **THEN** the system queries TMDB, ignores the unknown token, and reports the unqueried sources as `skipped`

#### Scenario: Token for a source that no longer exists

- **WHEN** a request supplies `sources=tmdb,mediux`
- **THEN** the system queries TMDB, ignores `mediux`, and the response contains no `mediux.pro` entry in `providers`

### Requirement: Identifier resolution across sources

The system SHALL resolve the external identifiers a source needs before querying it. Specifically it SHALL resolve, from TMDB, the work's TVDB identifier for shows and seasons — fanart.tv's television endpoint, TheTVDB's series endpoint and TVmaze's show lookup are all keyed on it — and the work's IMDb identifier for films, which is how TheTVDB's film records are located.

For TVmaze the system SHALL attempt the TVDB identifier first and SHALL fall back to the IMDb identifier when the TVDB identifier is unavailable, since TVmaze accepts either.

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

#### Scenario: TVmaze located by the TVDB identifier

- **WHEN** a `type=show` request resolves a show whose TVDB identifier is available
- **THEN** the system locates the TVmaze record with that identifier and includes any posters it returns

#### Scenario: TVmaze falls back to the IMDb identifier

- **WHEN** a resolved show has no TVDB identifier but does have an IMDb identifier
- **THEN** the system locates the TVmaze record with the IMDb identifier

#### Scenario: TVmaze has neither identifier

- **WHEN** a resolved show has neither a TVDB nor an IMDb identifier
- **THEN** TVmaze is reported as `no_data` and no TVmaze request is issued

#### Scenario: TVmaze has no record for the identifier

- **WHEN** the identifier lookup against TVmaze returns `404`
- **THEN** TVmaze is reported as `no_data` rather than `error`

### Requirement: Configuration is an explicit, enumerated set

The system SHALL read exactly four environment variables — `TMDB_API_KEY`, `FANART_API_KEY`, `TVDB_API_KEY` and `POSTERIA_API_KEY` — and SHALL NOT read any other. `MEDIUX_API_KEY` exists in the deployment but SHALL NOT be read, because Mediux is not a source.

TVmaze SHALL be served with no credential and SHALL NOT introduce an environment variable. Adding a source SHALL NOT by itself require a deployment change.

All provider base URLs, image size mappings, artwork type identifiers, timeouts, limits and tolerances SHALL be constants in code rather than configuration.

The system SHALL start and serve requests when any of these variables is unset, degrading by marking the affected sources `skipped`.

#### Scenario: No new variable is introduced

- **WHEN** the endpoint is deployed
- **THEN** it reads the same four environment variables the previous version read, and TVmaze serves without any credential being configured

#### Scenario: New variable rejected

- **WHEN** `TVDB_API_KEY` is set to a value the provider rejects
- **THEN** TheTVDB is reported as `skipped`, the rejection is logged, and every other source is unaffected

#### Scenario: Partial credentials

- **WHEN** `TMDB_API_KEY` is set but `FANART_API_KEY` is unset
- **THEN** the system serves TMDB artwork and reports fanart.tv as `skipped`

#### Scenario: Only the credential-free source is usable

- **WHEN** every credential except `TMDB_API_KEY` is unset and a `type=show` request is made
- **THEN** the system serves TMDB and TVmaze artwork and reports fanart.tv and TheTVDB as `skipped`

## ADDED Requirements

### Requirement: A source is queried only for media types it indexes

A source SHALL NOT be sent a request for a media type it structurally cannot hold. Such a source SHALL be reported as `no_data` without an upstream call being made.

TVmaze indexes television only. A `type=movie` or `type=collection` request SHALL therefore report `tvmaze` as `no_data` and SHALL issue no TVmaze request.

#### Scenario: Film requested

- **WHEN** a request supplies `type=movie` and does not exclude TVmaze
- **THEN** `providers` reports `tvmaze` as `no_data`, no TVmaze request is issued, and the response is not marked `partial`

#### Scenario: Collection requested

- **WHEN** a request supplies `type=collection` and does not exclude TVmaze
- **THEN** `providers` reports `tvmaze` as `no_data` and no TVmaze request is issued

#### Scenario: Inapplicable source does not affect the verdict

- **WHEN** a `type=movie` request succeeds with artwork from every applicable source
- **THEN** the response carries no `partial` code despite `tvmaze` reporting `no_data`

### Requirement: TVmaze artwork selection

For `type=show` the system SHALL return only TVmaze images whose type is `poster`, and SHALL discard images typed `banner`, `background`, `typography` or untyped.

For `type=season` the system SHALL return only the requested season's own image. The system SHALL NOT substitute a show-level TVmaze poster when the season has no image.

#### Scenario: Show posters only

- **WHEN** a `type=show` request retrieves TVmaze images including banners and backgrounds
- **THEN** every returned TVmaze poster is one TVmaze typed `poster`, and no banner or background appears

#### Scenario: Season image returned

- **WHEN** a `type=season` request resolves a season TVmaze holds an image for
- **THEN** that image is returned as a poster attributed to `tvmaze`

#### Scenario: Season without an image

- **WHEN** a `type=season` request resolves a season TVmaze holds no image for
- **THEN** TVmaze is reported as `no_data` and no show-level TVmaze poster is substituted

### Requirement: Every poster links back to its source

Each poster SHALL carry an absolute URL to the supplying source's page for the work, wherever that URL can be determined without guesswork, so that a user can see the artwork in its original context and a client can credit the source it came from.

That URL SHALL be derived only from identifiers the system already holds or from values the source itself supplied. It SHALL NOT be derived from the work's title, by slug or by any other string transformation. A source whose page cannot be addressed without such a transformation SHALL omit the link rather than carry a guessed one.

The link SHALL address the most specific page the source genuinely serves: for a `type=season` request, the season's own page where the source publishes one, and the work's page otherwise. A path segment the source accepts but ignores SHALL NOT be treated as a more specific page.

The system SHALL identify itself to upstream providers with a stable, descriptive user agent.

#### Scenario: Every source supplies its link

- **WHEN** a `type=show` request succeeds with artwork from TMDB, fanart.tv, TheTVDB and TVmaze
- **THEN** every returned poster carries an absolute URL to its own source's page for that show

#### Scenario: Link is built from identifiers, not titles

- **WHEN** a poster's source page is addressed by an identifier the system already holds
- **THEN** the system builds the link from that identifier and performs no additional upstream call to obtain it

#### Scenario: Title-derived addressing is refused

- **WHEN** a source's page can only be addressed by a slug derived from the work's title
- **THEN** the system omits the link for that source rather than constructing the slug

#### Scenario: Season poster links to the season

- **WHEN** a `type=season` response contains a poster from a source that publishes a season page
- **THEN** that poster's link addresses the season, and differs from the link the same source carries on a `type=show` response

#### Scenario: Season poster falls back to the work

- **WHEN** a `type=season` response contains a poster from a source that publishes no season page
- **THEN** that poster's link addresses the work rather than a season path the source would ignore

### Requirement: Licence-required attribution is marked

A poster whose source licence *requires* a link back SHALL be marked as such, distinctly from a poster carrying a link offered only as provenance. The mark SHALL be present only where the obligation is real, and absent otherwise rather than present and false.

TVmaze artwork is licensed CC BY-SA and SHALL be marked. TMDB, fanart.tv and TheTVDB impose no comparable term on artwork obtained through their APIs and SHALL NOT be marked.

A client SHALL therefore be able to determine, from the response alone, which links it is obliged to render and which it may present at its discretion.

#### Scenario: Licence-required poster is marked

- **WHEN** a response contains a poster whose `source` is `tvmaze`
- **THEN** that poster carries both the link and the mark identifying the link as licence-required

#### Scenario: Courtesy link is not marked

- **WHEN** a response contains a poster supplied by TMDB, fanart.tv or TheTVDB
- **THEN** that poster carries the link and omits the mark entirely, rather than carrying it set to false

#### Scenario: Obligation is discoverable without knowing the sources

- **WHEN** a client that has no built-in knowledge of any provider renders a response
- **THEN** it can identify every link it must render by the mark alone

### Requirement: The previous endpoint version is frozen

The `v1` endpoint SHALL continue to serve its existing contract unchanged. No file under `marquee/api/v1/` SHALL be modified in order to serve `v2`, and `v2` SHALL NOT include, require, or otherwise depend on any file under `marquee/api/v1/`.

Code needed by both versions SHALL be duplicated rather than shared, for the same reason it is duplicated between the Marquee endpoint and the legacy one: a client already deployed against `v1` must not be exposed to a `v2` edit.

A client SHALL be able to move from `v1` to `v2` by changing the path alone, with no other change to how it authenticates or shapes a request.

#### Scenario: v1 unchanged

- **WHEN** `v2` is deployed
- **THEN** `GET /marquee/api/v1/posters` behaves exactly as it did before, reports the same three sources, and no file under `marquee/api/v1/` has been modified

#### Scenario: v2 is self-contained

- **WHEN** `v2` serves a request
- **THEN** it loads only files under its own tree, and nothing under `marquee/api/v1/` or under `api/`

#### Scenario: Upgrading is a path change

- **WHEN** a client that works against `v1` reissues the identical request against `/marquee/api/v2/posters`
- **THEN** it is served, with the same identification header, the same parameters, and a response carrying the additional source
