## 1. Baseline

- [x] 1.1 Run `php marquee/api/v1/tests/resolve_test.php` and record the pass count, so any change to the existing assertions is visible rather than inferred.
- [x] 1.2 Confirm `marqueeTmdbWorkDetails()` and `marqueeTmdbSeason()` take only a `tmdb_id` (and season number), so the gather stage needs no change to accept a client-supplied id.

## 2. Parse and validate the parameter

- [x] 2.1 In `marquee/api/v1/lib/request.php`, parse `tmdb_id`: absent or empty → `null`; otherwise require digits only and a value ≥ 1, rejecting anything else with `marqueeInvalidRequest()` in the same idiom as `season` and `year`.
- [x] 2.2 Add `tmdb_id` to the returned request array and to the doc comment listing the parsed shape.
- [x] 2.3 Leave `q` required. Confirm a request with `tmdb_id` but no `q` still 400s on the existing `q` check.
- [x] 2.4 Add fixture assertions for the parser: valid id parsed, `abc` and `0` and `-1` rejected, absent → `null`.

## 3. Identify by id

- [x] 3.1 In `posters/index.php`, when `$request['tmdb_id']` is set, skip `marqueeTmdbSearch()` and `marqueeResolveWork()` and synthesise the winner per design decision 1: `tmdb_id` from the request, `title` from `q` as a placeholder, `year` null, `score` null.
- [x] 3.2 Ensure the artwork cache lookup still runs against the synthesised winner — the cache key is already keyed on `tmdb_id`, so an id request and a title request for the same work must share an entry.
- [x] 3.3 Confirm `marqueeBuildMatch()` overwrites the placeholder title and year from the details payload, so `match` describes the provider's record rather than the client's local title.
- [x] 3.4 Do not touch `lib/resolve.php`. Verify with `git diff --stat marquee/api/v1/lib/resolve.php` returning empty at the end of the change.

## 4. Fallback on an unknown id

- [x] 4.1 Detect the stale-id case: `details` status `404` from `marqueeTmdbWorkDetails()`. On that and only that, fall back to the title path — search, resolve, and continue as though no id had been supplied.
- [x] 4.2 Confirm a non-404 failure (timeout, 5xx) does **not** trigger the fallback and continues through the existing provider-outcome handling, per design decision 2.
- [x] 4.3 Confirm a successfully identified work with no artwork returns `200` with `posters: []` and issues **no** title search, per design decision 3. This is the case most likely to be "fixed" into a bug later — give it an explicit test and a comment naming the reason.
- [x] 4.4 Confirm an unknown id whose `q` also matches nothing returns `404 no_match`, with the failure debug block from the title attempt.

## 5. Response and debug

- [x] 5.1 Add `tmdb_id` to the `query` echo in the success payload — the value the client supplied, or `null`.
- [x] 5.2 Confirm `match.tmdb_id` reports the work actually described, so a fallback is detectable by comparing the two without debug.
- [x] 5.3 Extend the debug block to report how the work was identified: by id, by id-then-fallback, or by title. Include the title resolution's candidates when a fallback occurred.
- [x] 5.4 Confirm the cached-response debug branch still reports a coherent shape when the work was identified by id.

## 6. Tests

- [x] 6.1 Fixture assertions for the request parser from 2.4.
- [x] 6.2 In `verify_live.sh`, add: a movie by `tmdb_id=603` returning the 1999 film's artwork; the same id with a deliberately wrong `q` still returning the 1999 film, proving the title is not consulted.
- [x] 6.3 In `verify_live.sh`, add a season by the show's id: `type=season&season=2&tmdb_id=1396` returns season-2 artwork.
- [x] 6.4 In `verify_live.sh`, add the stale-id fallback: `tmdb_id=99999999&q=Breaking Bad&type=show` returns `200` for the real show, with `query.tmdb_id` and `match.tmdb_id` differing.
- [x] 6.5 In `verify_live.sh`, add the real-world case this change exists for: `q=Spider-Noir B&W&type=show` with the correct `tmdb_id` returns artwork where the title alone returns `404`.
- [x] 6.6 In `verify_live.sh`, confirm `query.tmdb_id` is `null` on a request that omits it, and that such requests behave exactly as before.

## 7. Verify

> 7.2 and 7.4 closed by a full `verify_live.sh` run against live providers: 81 passed,
> 0 failed. Spider-Noir B&W resolved via id 220102 to 92 posters where the title alone
> 404s; the id path issued no `search` call; a stale id fell back to the title with
> `query.tmdb_id=99999999` against `match.tmdb_id=1396`.

- [x] 7.1 Run the full fixture suite; every pre-existing assertion still passes.
- [x] 7.2 Run `verify_live.sh` with real credentials; all pre-existing checks plus the new ones pass.
- [x] 7.3 Confirm `git diff --stat` touches only `lib/request.php`, `posters/index.php` and the two test files — nothing under `api/`, no config constant, no new environment variable, no change to `lib/resolve.php`.
- [x] 7.4 Confirm a request with no `tmdb_id` produces a byte-identical response to the same request before this change, aside from the added `query.tmdb_id: null` — and, on debug requests only, the added `debug.identified_by: "title"`, which the task as written omitted. Both are new keys; no existing field changes value. The null-guards added to `score` and `rejected` evaluate identically whenever a resolution exists, which on this path it always does.
