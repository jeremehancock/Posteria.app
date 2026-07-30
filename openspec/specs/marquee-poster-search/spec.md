# marquee-poster-search Specification

## Purpose
TBD - created by archiving change add-marquee-poster-api. Update Purpose after archive.
## Requirements
### Requirement: Poster search endpoint

The system SHALL expose a poster search endpoint at `GET /marquee/api/v1/posters` that accepts a title and a media type and returns poster artwork for a single resolved work.

The endpoint SHALL respond with `Content-Type: application/json` and SHALL accept only the `GET` and `OPTIONS` methods.

#### Scenario: Endpoint responds to a valid search

- **WHEN** a client sends `GET /marquee/api/v1/posters?q=The+Matrix&type=movie&year=1999` with valid client identification
- **THEN** the system responds `200` with a JSON body containing `success`, `query`, `match`, `posters`, `total` and `providers`

#### Scenario: Unsupported method

- **WHEN** a client sends `POST /marquee/api/v1/posters`
- **THEN** the system responds `405` with `success: false` and `code: "method_not_allowed"`

### Requirement: Request parameters

The endpoint SHALL accept the following query parameters and no others:

| Param | Required | Values |
| --- | --- | --- |
| `q` | yes | non-empty string — the title to search for |
| `type` | yes | `movie`, `show`, `season`, or `collection` |
| `season` | required when `type=season` | integer ≥ 0 |
| `year` | no | four-digit integer |
| `limit` | no | integer between 1 and 500 |
| `sources` | no | comma-separated list of source tokens |
| `debug` | no | `true` |

`type` SHALL have no default value and SHALL NOT accept a combined or `all` mode. `season=0` SHALL denote the Specials season and SHALL be treated as a valid season number rather than as an absent value.

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
- `query` — the interpreted request: `q`, `type`, and `season` (`null` unless `type=season`)
- `match` — the resolved work's identity: `title`, `year`, `type`, `tmdb_id`, `imdb_id`, `tvdb_id`; identifiers that could not be resolved SHALL be `null`
- `posters` — a flat array of poster objects
- `total` — the number of distinct posters found for the resolved work before any `limit` is applied
- `providers` — a map of source name to outcome

Item-level metadata SHALL appear only in `match` and SHALL NOT be repeated on individual posters.

For `type=season`, `match` SHALL additionally carry a `season` object with `number`, `name`, `episode_count` and `air_date`.

#### Scenario: Movie response carries identity once

- **WHEN** a movie search succeeds
- **THEN** `match` carries the title, year and identifiers once, and each entry in `posters` carries only image-level fields

#### Scenario: Season response carries the resolved season

- **WHEN** a request supplies `q=Breaking Bad&type=season&season=2`
- **THEN** `match.season` contains `number: 2` together with the season's name, episode count and air date

### Requirement: Poster object fields

Each entry in `posters` SHALL contain:

- `url` — a resolvable absolute URL to the full-size image
- `thumb` — a resolvable absolute URL to a smaller rendition of the same image, present whenever the source can supply one
- `source` — the source that supplied that specific image

Each entry SHALL additionally carry `width`, `height`, `language` and `score` when the supplying source provides them. A field the source does not supply SHALL be omitted rather than filled with a guessed or duplicated value.

`url` and `thumb` SHALL be distinct renditions where the source offers more than one size; where it offers only one, `thumb` SHALL be omitted.

#### Scenario: Poster carries image metadata

- **WHEN** a source supplies image dimensions, language and a rating for a poster
- **THEN** that poster object carries `width`, `height`, `language` and `score`

#### Scenario: Source supplies no metadata

- **WHEN** a source supplies only an image URL
- **THEN** the poster object carries `url` and `source` and omits the metadata fields it has no value for

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

