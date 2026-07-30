## ADDED Requirements

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

## MODIFIED Requirements

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
