# marquee-poster-search Specification

## Purpose
TBD - created by archiving change add-marquee-poster-api. Update Purpose after archive.
## Requirements
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

### Requirement: Request parameters

The endpoint SHALL accept the following query parameters and no others:

| Param | Required | Values |
| --- | --- | --- |
| `q` | yes | non-empty string — the title to search for |
| `type` | yes | `movie`, `show`, `season`, or `collection` |
| `tmdb_id` | no | integer ≥ 1 — the provider's identifier for the work; the show's identifier when `type=season` |
| `season` | required when `type=season` | integer ≥ 0 |
| `year` | no | four-digit integer |
| `limit` | no | integer between 1 and 500 |
| `sources` | no | comma-separated list of source tokens |
| `debug` | no | `true` |

`type` SHALL have no default value and SHALL NOT accept a combined or `all` mode. `season=0` SHALL denote the Specials season and SHALL be treated as a valid season number rather than as an absent value.

`q` SHALL remain required even when `tmdb_id` is supplied, because it is the fallback when the identifier does not identify a work.

Unrecognised parameters SHALL be ignored rather than rejected. The season number SHALL be taken only from the `season` parameter and SHALL NOT be inferred from the text of `q`.

#### Scenario: Missing required parameter

- **WHEN** a request omits `q`, or omits `type`
- **THEN** the system responds `400` with `success: false` and `code: "invalid_request"` and an `error` naming the missing parameter

#### Scenario: Invalid type value

- **WHEN** a request supplies `type=tv` or any value outside `movie|show|season|collection`
- **THEN** the system responds `400` with `code: "invalid_request"`

#### Scenario: Season type without a season number

- **WHEN** a request supplies `type=season` with no `season` parameter
- **THEN** the system responds `400` with `code: "invalid_request"`

#### Scenario: Specials requested

- **WHEN** a request supplies `type=season&season=0`
- **THEN** the system resolves the Specials season and returns its artwork

#### Scenario: Season number embedded in the query text

- **WHEN** a request supplies `q=Stranger Things 4&type=show`
- **THEN** the system treats the whole string as the title and does not interpret `4` as a season number

#### Scenario: Identifier supplied without a title

- **WHEN** a request supplies `tmdb_id` but omits `q`
- **THEN** the system responds `400` with `code: "invalid_request"`

### Requirement: Resolution to a single work

The system SHALL resolve the query to exactly one work before gathering any artwork, and SHALL return artwork belonging only to that work.

Resolution SHALL be deterministic for a given set of inputs and SHALL apply, in order of decreasing weight: an exact match on normalised title, agreement with `year` when supplied, and provider popularity as the final tie-break. Normalisation SHALL be case-insensitive and SHALL ignore punctuation and leading articles.

For `type=collection`, normalisation SHALL additionally disregard a trailing `Collection` token on both the query and the candidate title, so that a collection title as recorded by the media server matches the provider's record of the same collection. This SHALL apply only to `type=collection`, and only to the token in trailing position; a title containing the word elsewhere SHALL be compared unaltered. Normalisation for `type=movie`, `type=show` and `type=season` SHALL be unchanged.

The score floor and the relative weights of the exact, prefix, and containment matches SHALL NOT be relaxed to admit a partial title match. A prefix match SHALL remain below the floor on its own, so that a sequel, spin-off or documentary whose title extends the query is never resolved in place of the work the query names.

For `type=season`, the system SHALL first resolve the show and then resolve the requested season within it.

#### Scenario: Sequels and related works are excluded

- **WHEN** a request supplies `q=The Matrix&type=movie&year=1999`
- **THEN** every returned poster belongs to the 1999 film and no poster belongs to its sequels, documentaries, or other works whose titles contain the query

#### Scenario: Resolution without a year hint

- **WHEN** a request supplies `q=The Matrix&type=movie` with no `year`
- **THEN** the system resolves to the single best-matching film and returns only its artwork

#### Scenario: Similarly titled shows are excluded

- **WHEN** a request supplies `q=Breaking Bad&type=show`
- **THEN** the response contains artwork for that show only and none from shows whose titles merely share words with the query

#### Scenario: Collection resolves to the collection itself

- **WHEN** a request supplies `q=Star Wars Collection&type=collection`
- **THEN** the response contains artwork belonging to the resolved collection and contains no artwork belonging to an individual film within it or to any unrelated collection

#### Scenario: Collection queried without the provider's suffix

- **WHEN** a request supplies `q=Star Wars&type=collection` with no `year`
- **THEN** the system resolves to the provider's `Star Wars Collection` record and responds `200` with its artwork

#### Scenario: Collection suffix is disregarded in either direction

- **WHEN** requests supply `q=Star Wars`, `q=Star Wars Collection` and `q=star wars collection`, each with `type=collection`
- **THEN** all three resolve to the same collection record

#### Scenario: Movie titled with the word Collection

- **WHEN** a request supplies `q=The Collection&type=movie`
- **THEN** the system resolves to the film of that title and the trailing token is not disregarded

#### Scenario: Collection whose title contains the word other than at the end

- **WHEN** a request supplies `type=collection` with a query whose title carries `Collection` other than as its final word
- **THEN** the word is retained for comparison and the title is matched in full

#### Scenario: Suffix handling does not admit a sequel

- **WHEN** a request supplies `q=The Matrix&type=movie` with no `year`, and the provider's results include `The Matrix`, `The Matrix Reloaded` and `The Matrix Collection`
- **THEN** the system resolves to `The Matrix` and to neither of the others

#### Scenario: Collection with no upstream record

- **WHEN** a request supplies `type=collection` with a locally invented collection title that the provider has no record of
- **THEN** the system responds `404` with `success: false` and `code: "no_match"`

#### Scenario: No work matches the query

- **WHEN** a request supplies a title that matches no work
- **THEN** the system responds `404` with `success: false` and `code: "no_match"`

### Requirement: Response shape

A successful response SHALL be a JSON object with exactly these top-level keys:

- `success` — boolean, `true`
- `query` — the interpreted request: `q`, `type`, `season` (`null` unless `type=season`), and `tmdb_id` (`null` unless supplied)
- `match` — the resolved work's identity: `title`, `year`, `type`, `tmdb_id`, `imdb_id`, `tvdb_id`; identifiers that could not be resolved SHALL be `null`
- `posters` — a flat array of poster objects
- `total` — the number of distinct posters found for the resolved work before any `limit` is applied
- `providers` — a map of source name to outcome

`query.tmdb_id` SHALL report the identifier the client supplied, and `match.tmdb_id` the identifier the response actually describes. A client SHALL therefore be able to detect that a fallback occurred by comparing the two, without requesting debug output.

Item-level metadata SHALL appear only in `match` and SHALL NOT be repeated on individual posters.

For `type=season`, `match` SHALL additionally carry a `season` object with `number`, `name`, `episode_count` and `air_date`.

#### Scenario: Movie response carries identity once

- **WHEN** a movie search succeeds
- **THEN** `match` carries the title, year and identifiers once, and each entry in `posters` carries only image-level fields

#### Scenario: Season response carries the resolved season

- **WHEN** a request supplies `q=Breaking Bad&type=season&season=2`
- **THEN** `match.season` contains `number: 2` together with the season's name, episode count and air date

#### Scenario: Supplied identifier is echoed

- **WHEN** a request supplies `tmdb_id=603`
- **THEN** `query.tmdb_id` is `603`

#### Scenario: Identifier absent from the request

- **WHEN** a request supplies no `tmdb_id`
- **THEN** `query.tmdb_id` is `null` and `match.tmdb_id` carries the resolved work's identifier

#### Scenario: Fallback is detectable from the response

- **WHEN** a request supplies an unknown `tmdb_id` and the system falls back to the title
- **THEN** `query.tmdb_id` reports the supplied identifier while `match.tmdb_id` reports the identifier of the work resolved by title, and the two differ

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

### Requirement: Season requests return season artwork only

A request with `type=season` SHALL return only artwork depicting the requested season. The system SHALL NOT substitute show-level artwork when season artwork is unavailable.

#### Scenario: Season artwork exists

- **WHEN** a request supplies `type=season&season=2` for a show with season-2 artwork
- **THEN** every returned poster is season-2 artwork and no returned poster is show artwork

#### Scenario: No season artwork exists

- **WHEN** a request supplies `type=season` for a season with no artwork from any source
- **THEN** the system responds `200` with `success: true`, `posters: []` and `total: 0`

### Requirement: De-duplication

The system SHALL return each distinct image URL at most once within a response. `total` SHALL reflect the count after de-duplication.

#### Scenario: The same image is offered twice

- **WHEN** the same image URL is produced more than once while gathering artwork
- **THEN** it appears exactly once in `posters`

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

### Requirement: Server-side caching

The system SHALL cache assembled poster results server-side, keyed on the resolved work and the request's shaping parameters rather than on the raw query string, so that different spellings of the same title share a cache entry.

The cache SHALL use only the local filesystem or APCu and SHALL require no configuration and no new environment variable. Cached entries SHALL expire after a bounded time-to-live. A cache read or write failure SHALL NOT fail the request.

Responses that reported a provider failure SHALL NOT be cached.

#### Scenario: Repeat search is served from cache

- **WHEN** the same work is searched twice within the cache time-to-live
- **THEN** the second response is served without re-querying the upstream sources and is equivalent to the first

#### Scenario: Different queries resolve to the same work

- **WHEN** two requests with differing `q` spellings and `year` hints resolve to the same work with the same shaping parameters
- **THEN** the second is served from the same cache entry as the first

#### Scenario: Cache store unavailable

- **WHEN** the cache directory is unwritable
- **THEN** the request still completes normally by querying the sources directly

### Requirement: Machine-readable failures

Every failure response SHALL be a JSON object of the form `{ "success": false, "code": "<code>", "error": "<human readable>" }` with an HTTP status matching the condition:

| Condition | Status | `code` |
| --- | --- | --- |
| Malformed or missing required parameters | 400 | `invalid_request` |
| Client identification missing or unrecognised | 401 | `unauthorized` |
| Unsupported HTTP method | 405 | `method_not_allowed` |
| Query matched no work | 404 | `no_match` |
| Rate limit exceeded | 429 | `rate_limited`, plus `retry_after` in seconds |
| Every source failed | 503 | `upstream_unavailable` |

A resolved work with genuinely no artwork SHALL be reported as success, not as a failure.

When some but not all sources failed, the system SHALL respond `200` with `success: true`, a `code` of `partial`, and a `providers` map identifying which sources failed.

#### Scenario: Unknown title

- **WHEN** a request supplies a title matching no work
- **THEN** the system responds `404` with `code: "no_match"` and not `200` with an empty list

#### Scenario: Resolved work with no artwork

- **WHEN** a work resolves successfully but no source has artwork for it
- **THEN** the system responds `200` with `success: true`, `posters: []`, and no error code

#### Scenario: Partial source failure

- **WHEN** one source errors while another returns artwork
- **THEN** the system responds `200` with `success: true`, `code: "partial"`, the artwork that was retrieved, and a `providers` map marking the failed source as `error`

#### Scenario: Total source failure

- **WHEN** every source errors
- **THEN** the system responds `503` with `code: "upstream_unavailable"`

### Requirement: Debug output

When `debug=true` is supplied, the response SHALL carry an additional `debug` key describing the resolution decision and the upstream calls made. The `debug` key SHALL be absent otherwise, and its presence SHALL NOT alter any other field.

The `debug` key SHALL report how the work was identified: by supplied identifier, by supplied identifier after which the identifier proved unknown and the title was used instead, or by title alone.

The `debug` key SHALL be present on a `no_match` failure as well as on a success, and SHALL describe the resolution decision that led to the failure together with the upstream calls made.

Where no candidate was resolved, that description SHALL report the normalised form of the query, the score floor in force, and the candidates the provider returned with the score each was awarded — so that a query the provider had no record of is distinguishable from one whose best candidate scored below the floor. Where a work resolved but the requested season within it did not, it SHALL report the resolved work instead.

A failure response carrying `debug` SHALL retain its status code and its `success`, `code` and `error` fields unchanged.

#### Scenario: Debug requested

- **WHEN** a request supplies `debug=true`
- **THEN** the response carries a `debug` object describing the resolved candidate, the rejected candidates, and each upstream call with its outcome

#### Scenario: Debug not requested

- **WHEN** a request omits `debug`
- **THEN** the response contains no `debug` key

#### Scenario: Debug requested on an unmatched query

- **WHEN** a request supplies `debug=true` and no candidate scores at or above the floor
- **THEN** the system responds `404` with `code: "no_match"` and a `debug` object reporting the normalised query, the score floor, and the scored candidates including the highest-scoring rejected one

#### Scenario: Debug requested when the provider returned nothing

- **WHEN** a request supplies `debug=true` and the provider returned no candidates at all
- **THEN** the system responds `404` with `code: "no_match"` and a `debug` object whose candidate list is empty

#### Scenario: Debug requested on a season that does not exist

- **WHEN** a request supplies `debug=true` with `type=season` for a season the resolved show does not have
- **THEN** the system responds `404` with `code: "no_match"` and a `debug` object reporting the resolved show and the upstream calls made

#### Scenario: Debug not requested on an unmatched query

- **WHEN** a request omits `debug` and no work matches
- **THEN** the `404` response carries only `success`, `code` and `error`

#### Scenario: Debug reports identification by id

- **WHEN** a request supplies `debug=true` and a `tmdb_id` that identifies a work
- **THEN** the `debug` object reports that the work was identified by the supplied identifier and records no title search

#### Scenario: Debug reports a fallback

- **WHEN** a request supplies `debug=true` and a `tmdb_id` the provider reports as unknown
- **THEN** the `debug` object reports that the identifier was unknown and that the work was identified by title, and carries the title resolution's candidates

### Requirement: Identification by provider id

When a request supplies `tmdb_id`, the system SHALL identify the work directly from that identifier and SHALL NOT perform title resolution. The identifier SHALL be interpreted according to `type`: for `type=season` it identifies the **show**, and the season within it SHALL be taken from `season`.

Where the identifier does not identify a work — the provider reports it as unknown — the system SHALL fall back to resolving `q` by title and SHALL serve the result of that resolution, so that an identifier which has gone stale in the client's records behaves exactly as a request that supplied none.

The system SHALL NOT fall back to title resolution for any other reason. In particular, a work that is identified successfully but has no artwork SHALL be reported as a resolved work with no posters, and SHALL NOT cause a title search whose results would belong to a different work.

The system SHALL NOT combine artwork obtained by identifier with artwork obtained by title in one response. Exactly one of the two SHALL determine the work a response describes.

`year` SHALL be ignored when a work is identified by `tmdb_id`, since the identifier is unambiguous. `q` SHALL remain required so that the fallback is always available.

#### Scenario: Work identified by id

- **WHEN** a request supplies `q=Spider-Noir B&W&type=show&tmdb_id=222766`
- **THEN** the system returns artwork for the work bearing that identifier, and `match.tmdb_id` is that identifier

#### Scenario: Identifier makes title matching irrelevant

- **WHEN** a request supplies a `q` that would not resolve on its own, together with a `tmdb_id` that identifies a work
- **THEN** the system responds `200` with that work's artwork rather than `404`

#### Scenario: Season identified by the show's id

- **WHEN** a request supplies `type=season&season=2&tmdb_id=1396`
- **THEN** the system returns season-2 artwork for the show bearing that identifier

#### Scenario: Stale identifier falls back to the title

- **WHEN** a request supplies `q=Breaking Bad&type=show&tmdb_id=99999999` and the provider reports that identifier as unknown
- **THEN** the system resolves `Breaking Bad` by title and responds `200` with that work's artwork

#### Scenario: Stale identifier whose title also fails

- **WHEN** a request supplies an unknown `tmdb_id` and a `q` that matches no work
- **THEN** the system responds `404` with `code: "no_match"`

#### Scenario: Identified work has no artwork

- **WHEN** a request supplies a `tmdb_id` that identifies a work for which no source has artwork
- **THEN** the system responds `200` with `success: true`, `posters: []` and `total: 0`, and does not search by title

#### Scenario: Identifier is not a valid integer

- **WHEN** a request supplies `tmdb_id=abc` or `tmdb_id=0`
- **THEN** the system responds `400` with `code: "invalid_request"`

#### Scenario: No identifier supplied

- **WHEN** a request omits `tmdb_id`
- **THEN** the system resolves the work by title exactly as it does when the parameter does not exist

