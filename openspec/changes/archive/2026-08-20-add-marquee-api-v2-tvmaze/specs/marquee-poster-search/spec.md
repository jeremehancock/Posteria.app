## MODIFIED Requirements

### Requirement: Poster search endpoint

The system SHALL expose a poster search endpoint at `GET /marquee/api/v2/posters` that accepts a title and a media type and returns poster artwork for a single resolved work.

The endpoint SHALL respond with `Content-Type: application/json` and SHALL accept only the `GET` and `OPTIONS` methods.

The `v1` endpoint SHALL remain reachable at `GET /marquee/api/v1/posters` serving its existing contract, so that a client upgrades on its own schedule.

#### Scenario: Endpoint responds to a valid search

- **WHEN** a client sends `GET /marquee/api/v2/posters?q=The+Matrix&type=movie&year=1999` with valid client identification
- **THEN** the system responds `200` with a JSON body containing `success`, `query`, `match`, `posters`, `total` and `providers`

#### Scenario: Unsupported method

- **WHEN** a client sends `POST /marquee/api/v2/posters`
- **THEN** the system responds `405` with `success: false` and `code: "method_not_allowed"`

#### Scenario: Both versions are reachable

- **WHEN** the same request is sent to `/marquee/api/v1/posters` and to `/marquee/api/v2/posters`
- **THEN** both are served, and only the `v2` response carries a `tvmaze` entry in `providers`

### Requirement: Poster object fields

Each entry in `posters` SHALL contain:

- `url` — a resolvable absolute URL to the full-size image
- `thumb` — a resolvable absolute URL to a smaller rendition of the same image, present whenever the source can supply one
- `source` — the source that supplied that specific image

Each entry SHALL additionally carry `width`, `height`, `language` and `score` when the supplying source provides them.

Each entry SHALL carry `page` — an absolute URL to the supplying source's page for the work — wherever that URL can be determined without deriving it from the work's title. An entry whose source page cannot be addressed that way SHALL omit `page` rather than carry a guessed URL.

An entry whose source licence requires the link to be rendered SHALL additionally carry `attribution_required` set to `true`. Entries carrying `page` as provenance only SHALL omit `attribution_required` rather than carry it set to `false`.

A field the source does not supply SHALL be omitted rather than filled with a guessed or duplicated value. In particular, a designation such as "this is the primary image" SHALL NOT be reported as a `score`, because it is not a rating and has no scale comparable to one.

`url` and `thumb` SHALL be distinct renditions where the source offers more than one size; where it offers only one, `thumb` SHALL be omitted.

#### Scenario: Poster carries image metadata

- **WHEN** a source supplies image dimensions, language and a rating for a poster
- **THEN** that poster object carries `width`, `height`, `language` and `score`

#### Scenario: Source supplies no metadata

- **WHEN** a source supplies only an image URL
- **THEN** the poster object carries `url` and `source` and omits the metadata fields it has no value for

#### Scenario: Every poster carries its source link

- **WHEN** a response contains posters from TMDB, fanart.tv, TheTVDB and TVmaze
- **THEN** every one carries `page` with an absolute URL to its own source's page for the work

#### Scenario: Licence-required link is marked

- **WHEN** a poster is supplied by a source whose licence requires the link to be rendered
- **THEN** that poster carries `attribution_required: true` alongside `page`

#### Scenario: Provenance link is unmarked

- **WHEN** a poster is supplied by a source that imposes no attribution obligation
- **THEN** that poster carries `page` and omits `attribution_required` entirely

#### Scenario: TVmaze show poster

- **WHEN** a `type=show` response contains a TVmaze poster
- **THEN** it carries `url`, `thumb`, `width`, `height`, `source`, `page` and `attribution_required`, and omits `language` and `score`

#### Scenario: TVmaze season poster

- **WHEN** a `type=season` response contains a TVmaze poster
- **THEN** it carries `url`, `thumb`, `source`, `page` and `attribution_required`, and omits `width`, `height`, `language` and `score`, because TVmaze publishes no dimensions for a season image

#### Scenario: TMDB poster carries a link but no obligation

- **WHEN** a response contains a TMDB poster
- **THEN** it carries `page` addressing that work on TMDB, and omits `attribution_required`

### Requirement: Deterministic ordering and limit

The system SHALL return posters best-first under a deterministic ranking combining the source's rating, image resolution, and language match against the request. Ties SHALL be broken deterministically so that repeated identical requests return the same order.

A poster carrying neither a rating nor a language SHALL rank on resolution within the language-neutral band, and SHALL NOT be promoted or demoted on the basis of which source supplied it.

The system SHALL apply a default limit of 200 posters when `limit` is not supplied, SHALL honour a supplied `limit` up to a maximum of 500, and SHALL report the pre-limit count in `total`.

#### Scenario: Default cap applied

- **WHEN** a resolved work has more posters than the default limit and the request supplies no `limit`
- **THEN** `posters` contains 200 entries and `total` reports the full pre-limit count

#### Scenario: Ordering is stable

- **WHEN** the same request is issued twice
- **THEN** both responses list the same posters in the same order

#### Scenario: Limit above the maximum

- **WHEN** a request supplies `limit=5000`
- **THEN** the system returns at most 500 posters rather than rejecting the request

#### Scenario: Unrated posters rank on resolution

- **WHEN** a response mixes TVmaze posters, which carry no rating, with rated posters from other sources
- **THEN** the TVmaze posters are ordered among themselves by pixel area, largest first, and the order is identical on a repeat request
