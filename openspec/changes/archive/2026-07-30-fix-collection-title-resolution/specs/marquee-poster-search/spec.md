## MODIFIED Requirements

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
