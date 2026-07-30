## 1. Baseline

- [x] 1.1 Run `php marquee/api/v1/tests/resolve_test.php` and record the pass/fail counts, so any change to the existing 71 assertions is visible rather than inferred.
- [x] 1.2 Run `grep -rn "marqueeScoreCandidate\|marqueeNormaliseTitle\|marqueeResolveWork" marquee/` and confirm the call sites are only those listed in design.md decisions 1–4 (three in `resolve.php`, two in `posters/index.php`, three `marqueeNormaliseTitle` assertions plus nine `marqueeResolveWork` calls in the test file).

## 2. Failing tests first

- [x] 2.1 In `marquee/api/v1/tests/resolve_test.php`, extend the collection fixture so the `Star Wars` result set contains the `Star Wars Collection` record alongside at least one sequel-shaped distractor (e.g. `Star Wars Holiday Special`-style entry in the movie fixture used by 2.3).
- [x] 2.2 Add assertions that `Star Wars` + `type=collection` resolves to the `Star Wars Collection` record's `tmdb_id`, that `Star Wars Collection` still resolves to the same id, and that `star wars collection` resolves to the same id. Confirm the first fails now with the score at 30.
- [x] 2.3 Add the movie guard: `The Matrix` + `type=movie` with no year, against a fixture containing `The Matrix` (1999), `The Matrix Reloaded` and `The Matrix Collection`, asserts the resolved `tmdb_id` is the 1999 film. Assert the id, not merely that a result was returned.
- [x] 2.4 Add `The Collection` + `type=movie` resolving to the film of that title, proving the strip does not fire outside `type=collection`.
- [x] 2.5 Add a collection query with no upstream record still returning `null`, and a `q=Collection` + `type=collection` case that does not match an unrelated candidate.
- [x] 2.6 Confirm the new assertions from 2.2 fail and the pre-existing ones still pass, so the fix is demonstrated rather than assumed.

## 3. Type-aware normalisation

- [x] 3.1 Add `marqueeNormaliseTitleForType(string $title, string $type): string` to `marquee/api/v1/lib/resolve.php` per design decision 1: delegate to `marqueeNormaliseTitle()`, return unchanged unless `$type === 'collection'`, then strip `/\s+collection$/`, and fall back to the unstripped form if the strip empties the string. Comment why the strip is scoped to collections and to trailing position.
- [x] 3.2 Leave `marqueeNormaliseTitle()` byte-for-byte unchanged — it is shared with movie, show and season scoring and with the artwork cache key.
- [x] 3.3 Add unit assertions for the wrapper: suffix stripped for `collection`, retained for `movie` and `show`, mid-string occurrence retained (`The Criterion Collection Presents`), and a bare `Collection` not reduced to an empty string.

## 4. Wire the wrapper into scoring

- [x] 4.1 Add a `string $type` parameter to `marqueeScoreCandidate()` and replace its `marqueeNormaliseTitle($title)` call with `marqueeNormaliseTitleForType($title, $type)`. Update the doc comment to note the type-aware comparison.
- [x] 4.2 In `marqueeResolveWork()`, normalise the query once via `marqueeNormaliseTitleForType($query, $type)` and pass `$type` through to `marqueeScoreCandidate()`.
- [x] 4.3 Do not touch `RESOLVE_SCORE_FLOOR` or any weight in `marqueeScoreCandidate()`. Verify with `git diff marquee/api/v1/lib/config.php` returning empty and the literals `60.0`, `25.0`, `20.0`, `15.0`, `20.0`, `10.0` and `5.0` unchanged in the diff.
- [x] 4.4 Leave `marqueeCandidateFacts()` alone — `titles[0]` doubles as the display title consumed by `marqueeBuildMatch()`.
- [x] 4.5 Run the suite: the 2.2 assertions now pass, `Star Wars` scores 60 + popularity rather than 30, and every pre-existing assertion still passes.

## 5. Resolution cache key

- [x] 5.1 Switch the `$resolutionKey` construction at `marquee/api/v1/posters/index.php:63` to `marqueeNormaliseTitleForType($request['q'], $request['type'])`, so the suffixed and unsuffixed forms share one resolution entry.
- [x] 5.2 Leave the artwork `$cacheKey` alone — it is already keyed on the resolved `tmdb_id`, not on the query.

## 6. Diagnostics from the resolver

- [x] 6.1 Add the trailing `?array &$diagnostics = null` parameter to `marqueeResolveWork()` per design decision 4, populated on every call with `query_normalised`, `score_floor` and `candidates` (top-first, same shape as the existing `rejected` entries, capped at 10).
- [x] 6.2 Populate `candidates` as an empty array when the provider returned no results or none survived the facts filter, so an empty list means "nothing upstream" unambiguously.
- [x] 6.3 Confirm the `?array` return type and the "one work or nothing" contract are unchanged, and that the two fixture assertions comparing `marqueeResolveWork(...) === null` still pass untouched.

## 7. Debug on the failure paths

- [x] 7.1 In `posters/index.php`, capture the diagnostics from the `marqueeResolveWork()` call and, when `$request['debug']` is set, pass `['debug' => ['resolution' => $diagnostics, 'calls' => [...the search call...]]]` as the `$extra` argument to the `no_match` `marqueeSendFailure()`.
- [x] 7.2 Include the TMDB search call's `status`, `error` and `timed_out` in that block's `calls`, matching the shape `$debugCalls` entries already use.
- [x] 7.3 Add a debug block to the season `no_match` at `index.php:160`: the resolved winner plus the accumulated `$debugCalls`, gated on `$request['debug']`.
- [x] 7.4 Confirm both 404s carry no `debug` key when `debug` is absent, and that adding the block leaves the status code and the `success`/`code`/`error` fields unchanged.
- [x] 7.5 Confirm the resolution 404 is still emitted before anything is written to the resolution cache — a rejection must not be cached.

## 8. Verify

> 8.4–8.6 closed by a full `verify_live.sh` run against live providers: 61 passed,
> 0 failed. Bare `Star Wars`/`Alien`/`Harry Potter` returned 80/34/40 posters — the
> same counts the handoff measured for the suffixed forms — and the Christmas Movies
> 404 reported `candidates=0` with `score_floor=40`.

- [x] 8.1 Run the full suite and confirm every pre-existing assertion still passes alongside the new ones, with the total count up by the number added.
- [x] 8.2 Re-run the grep from 1.2 and confirm no call site was missed by the signature changes in 4.1 and 6.1.
- [x] 8.3 Confirm `git diff --stat` touches only `marquee/api/v1/lib/resolve.php`, `marquee/api/v1/posters/index.php`, `marquee/api/v1/tests/resolve_test.php` and (per 8.7) `marquee/api/v1/tests/verify_live.sh` — nothing under `api/`, no config constant, no new environment variable.
- [x] 8.4 Against the live endpoint, confirm `q=Star Wars&type=collection` returns 200 with posters, `q=Star Wars Collection&type=collection` still does and resolves to the same `tmdb_id`, and `q=Alien&type=collection` and `q=Harry Potter&type=collection` now succeed.
- [x] 8.5 Against the live endpoint, confirm `q=The Matrix&type=movie` still resolves to the 1999 film and `q=Breaking Bad&type=show` is unaffected.
- [x] 8.6 Against the live endpoint, confirm `q=Christmas Movies&type=collection&debug=true` returns 404 with a debug block whose `candidates` list is empty, and that a below-floor case shows its candidate with a score under `score_floor`.
- [x] 8.7 Update `marquee/api/v1/tests/verify_live.sh` with the collection-vocabulary checks from 8.4 and 8.6, so the live suite covers the vocabulary Marquee actually sends rather than only the TMDB-style names that let this defect ship.
