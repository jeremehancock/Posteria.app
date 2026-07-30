## Why

`GET /marquee/api/v1/posters?type=collection` returns `404 no_match` for essentially every
real Plex collection title. TMDB names every collection record `"<Franchise> Collection"`;
Plex collection titles almost never carry that word. Marquee sends the Plex title verbatim —
which is correct, since title matching is this endpoint's job — so `q=Star Wars` fails while
`q=Star Wars Collection` returns 80 posters. Measured live against the deployed endpoint:
`Star Wars`, `Alien`, `Harry Potter` and `Matrix` all 404; every one succeeds with
` Collection` appended. Collections are effectively unusable from the shipped Marquee client.

The cause is confirmed by running this repo's own scorer: for `Star Wars` vs
`Star Wars Collection` the exact-match branch misses and the prefix branch fires, scoring
`25 + ≤5 popularity = 30`, below `RESOLVE_SCORE_FLOOR` of 40, so `marqueeResolveWork()`
returns `null`.

Diagnosing that required reading the scorer and running it by hand, because the second half of
this change is missing: `debug=true` returns no `debug` key on a 404 — the one case where the
rejected-candidate list and their scores matter most, since it separates "TMDB returned
nothing" from "TMDB returned the right record and the floor rejected it".

## What Changes

- Make the trailing ` Collection` token invisible to comparison on both sides of the match,
  for `type=collection` only, so the exact-match branch fires (60 points) instead of the
  prefix branch (25). `Star Wars`, `Star Wars Collection` and `star wars collection` all
  reduce to `star wars` and match the record exactly.
- Keep `RESOLVE_SCORE_FLOOR` at 40 and the prefix weight at 25. Both are deliberately
  excluded: sequels are prefix matches too and score 26–28 (`The Matrix Reloaded` 28.00,
  `Alien Resurrection` 27.00) while collection prefix matches score 28–30, so **no floor
  value admits one and excludes the other**. Lowering the floor to 30 would resurrect the
  exact "artwork for Reloaded, Revolutions and a making-of documentary" failure the resolver
  exists to prevent.
- Scope the strip narrowly: collections only, trailing token only. A movie legitimately titled
  *The Collection* (2012) exists, and mid-string occurrences (*The Criterion Collection
  Presents…*) must not be mangled. Movie, show and season scoring are untouched.
- Emit a `debug` object on the `no_match` 404 when `debug=true`, carrying the normalised query,
  the score floor, and the scored candidates that were rejected — including the top-scoring
  one that fell short.
- Add fixture tests in Plex's vocabulary. The existing 71 fixture tests and 45 live checks
  missed this because every collection fixture uses a TMDB-style name, so no test ever
  exercised the only vocabulary Marquee actually sends.

Explicitly out of scope, per the handoff: custom Plex collections with no TMDB record at all
("Marvel Cinematic Universe", "Christmas Movies") correctly continue to 404, and locally
annotated title variants (`Spider-Noir B&W`) are being handled client-side with an editable
query — no fuzzy matching is added here.

No breaking changes. Every query that resolves today resolves to the same work afterwards.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `marquee-poster-search`: the resolution requirement gains a normalisation rule for the
  collection-type suffix and a scenario for a Plex-vocabulary collection query; the debug
  requirement extends to failure responses.

## Impact

- `marquee/api/v1/lib/resolve.php` — type-aware normalisation at the collection comparison
  sites; `marqueeResolveWork()` must return the scored-candidate list on rejection instead of
  a bare `null`.
- `marquee/api/v1/posters/index.php` — pass a debug block to `marqueeSendFailure()` on the
  `no_match` path; derive the resolution cache key from the type-aware normalisation so
  `Star Wars` and `Star Wars Collection` share one entry.
- `marquee/api/v1/tests/resolve_test.php` — new collection-vocabulary cases plus a movie guard
  proving movie matching did not loosen.
- No change to `lib/config.php` constants, no new environment variable, nothing under `api/`,
  no client-side change in Marquee.
