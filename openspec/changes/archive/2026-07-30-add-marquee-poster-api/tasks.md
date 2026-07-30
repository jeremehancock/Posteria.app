## 1. Scaffold and access layer

- [x] 1.1 Create the `marquee/api/v1/` tree: `posters/index.php`, `time.php`, and a `lib/` directory; confirm `GET /marquee/api/v1/posters` and `GET /marquee/api/v1/time` route under `php -S` with no rewrite rules
- [x] 1.2 Copy `api/time.php` verbatim into `marquee/api/v1/time.php` (permissive CORS, no client identification required)
- [x] 1.3 Add `lib/config.php` reading only `TMDB_API_KEY`, `FANART_API_KEY`, `MEDIUX_API_KEY`, `POSTERIA_API_KEY` via the `$_ENV['X'] ?? getenv('X')` pattern, with all base URLs, sizes, timeouts, limits and tolerances as in-code constants
- [x] 1.4 Add a define-guard to every file under `lib/` so a direct URL request produces nothing useful, and keep all logic in functions/classes with no top-level side effects
- [x] 1.5 Emit CORS headers (`Access-Control-Allow-Origin: *`, `GET`, `X-Client-Info`) and answer `OPTIONS` with `204` before any identification check; reject non-`GET` methods with `405 method_not_allowed`
- [x] 1.6 Implement `lib/auth.php`: base64/JSON decode of `X-Client-Info`, recognised-`name` check, numeric `ts` check, ±24h tolerance accepting future timestamps; `401 unauthorized` when the header is absent, malformed, unrecognised, or outside tolerance
- [x] 1.7 Accept an optional `key` query parameter matching `POSTERIA_API_KEY` as an alternative path; never require it, and never let an incorrect `key` reject a request carrying valid client identification
- [x] 1.8 Implement `lib/store.php`: APCu-when-available with a `sys_get_temp_dir()` file fallback, exposing get/set with TTL and an atomic counter increment; every failure path returns a miss rather than throwing
- [x] 1.9 Implement rate limiting on the store: fixed per-IP windows at 60/minute and 3,000/hour, first request from an unseen IP always served, `429` with `retry_after` in seconds, store failure serves the request
- [x] 1.10 Implement request logging by client name/version, query parameters, resolved work and per-source outcomes; never log the `key` value, and never fail a request on a logging error

## 2. Request contract and error envelope

- [x] 2.1 Implement `lib/response.php`: the success envelope (`success`, `query`, `match`, `posters`, `total`, `providers`) and the failure envelope (`{success:false, code, error}`) with the status mapping from the spec
- [x] 2.2 Parse and validate `q`, `type` (`movie|show|season|collection`, no default, no `all`), `season` (integer ≥ 0, required for `type=season`), `year`, `limit` (1–500), `sources`, `debug`; ignore unrecognised parameters
- [x] 2.3 Treat `season=0` as Specials throughout — every presence check uses `!== null`, never truthiness — and never infer a season number from the text of `q`
- [x] 2.4 Clamp `limit` above 500 rather than rejecting; default to 200 when absent
- [x] 2.5 Return `400 invalid_request` naming the offending parameter for missing `q`, missing or invalid `type`, `type=season` without `season`, and a `sources` list containing no recognised token

## 3. HTTP client and TMDB integration

- [x] 3.1 Copy the `curl_multi` parallel `HttpClient` (old L46–122) into `lib/http.php`, adding a per-request timeout and per-request error capture so each call returns an explicit outcome rather than a bare success flag
- [x] 3.2 Copy the TMDB client (old L242–332) into `lib/tmdb.php`: search movie/tv/collection, details with `append_to_response=external_ids`, season details, and images with `include_image_language=en,null`
- [x] 3.3 Implement title normalisation: lowercase, strip punctuation and diacritics, collapse whitespace, drop a leading article
- [x] 3.4 Implement candidate scoring: exact normalised-title match, then `year` agreement (exact, then ±1), then prefix/substring match, then TMDB `popularity` as tie-break; apply a minimum score floor
- [x] 3.5 Implement `resolveWork()` returning exactly one candidate or a no-match signal; treat `year` as a hint that never causes `no_match` when the title matches exactly; return `404 no_match` below the floor
- [x] 3.6 Resolve `match` identity from the chosen candidate's details: `title`, `year`, `type`, `tmdb_id`, `imdb_id`, `tvdb_id` (from `external_ids`), with `null` for anything unresolved
- [x] 3.7 For `type=season`, resolve the show then call `/tv/{id}/season/{n}` for the season identity (`number`, `name`, `episode_count`, `air_date`) and `/tv/{id}/season/{n}/images` for its posters
- [x] 3.8 Map TMDB images to poster objects: `url` at `original`, `thumb` at `w342`, plus `width`, `height`, `language` (from `iso_639_1`) and `score` (from `vote_average`)

## 4. Other sources and the providers map

- [x] 4.1 Define the source outcome vocabulary (`ok`, `no_data`, `error`, `skipped`) and have every source fetch return an explicit outcome instead of being silently skipped at the call site
- [x] 4.2 Implement source selection from `sources` (`tmdb`, `fanart`, `mediux`); ignore unrecognised tokens; report unqueried sources as `skipped`; report sources with no credential as `skipped`
- [x] 4.3 Implement fanart.tv: `/movies/{tmdbId}` for movies, `/tv/{tvdbId}` for shows and seasons; report `no_data` (not `error`) when `tvdb_id` could not be resolved; attribute posters as `fanart.tv`
- [x] 4.4 Filter fanart `seasonposter` entries to the requested season number for `type=season`, and never let a fanart season poster set item-level identity or a poster's `source` to another provider
- [x] 4.5 Probe the production Mediux API host with the existing `MEDIUX_API_KEY` and the Directus-style query shape (old L410/L435/L443) before writing the integration; record what the probe returns
- [x] 4.6 Based on the probe, either implement Mediux (attributed `mediux.pro`, filtered to the requested season for `type=season`, outcome reported honestly) or omit it from the source list entirely — no advertised-but-never-contributing state; report the finding if the credential is unset or expired
- [x] 4.7 Keep `tvdb_id` resolution from TMDB `external_ids` and verify fanart TV posters still return for `type=show` and `type=season` (TheTVDB itself is covered by group 8)
- [x] 4.8 Issue all source requests for one search concurrently through the parallel client with a bounded timeout; treat a timeout as `error` for that source only
- [x] 4.9 Assemble the `providers` map on every response, and set `code: partial` on a `200` when some but not all queried sources errored; return `503 upstream_unavailable` when every queried source errored

## 5. Assembly, ordering and caching

- [x] 5.1 Merge posters from all sources, de-duplicate on the exact `url` string, and compute `total` from the de-duplicated set before any limit
- [x] 5.2 Omit `thumb`, `width`, `height`, `language` and `score` when the supplying source has no value, rather than duplicating `url` or guessing
- [x] 5.3 Sort deterministically: language match against `en`, then `score` (unrated sorting below rated), then pixel area, then a stable tie-break on `url`; apply `limit` after sorting
- [x] 5.4 Guarantee `type=season` returns season artwork only, with `posters: []` and `total: 0` when there is none — never fall back to show art
- [x] 5.5 Implement the response cache on the store: key on `type` + resolved TMDB id + season + sorted `sources` + `limit`, 24-hour TTL, never cache a `partial` response, fall through to a live fetch on any cache failure
- [x] 5.6 Implement `debug=true`: emit a `debug` object with the resolved candidate, the rejected candidates and their scores, and each upstream call with its outcome; omit the key entirely otherwise and change nothing else

## 6. Verification

- [x] 6.1 `?q=The+Matrix&type=movie&year=1999` returns only the 1999 film, roughly 193 de-duplicated posters, payload under 100 KB
- [x] 6.2 `?q=The+Matrix&type=movie` with no year still excludes Reloaded, Revolutions, Resurrections and the documentaries
- [x] 6.3 `?q=Breaking+Bad&type=show` contains no `The Bad Guys: The Series`; `?q=Stranger+Things&type=show` resolves correctly with no season inference
- [x] 6.4 `?q=Breaking+Bad&type=season&season=2` returns the genuine season-2 posters, zero show posters and no duplicate URLs; `season=0` returns Specials art
- [x] 6.5 `?q=Star+Wars+Collection&type=collection` contains no LEGO / Robot Chicken / Ewok / "The Man Who Saved the World" entries and no individual film's posters
- [x] 6.6 `?q=Zzzznotarealtitle&type=movie` returns `404 no_match`, not `200` with an empty list
- [x] 6.7 No duplicate `url` in any response; every `url` resolves; `thumb` present wherever the source allows
- [x] 6.8 `providers` reflects per-source outcome accurately, including a forced single-source failure producing `code: partial` and a forced total failure producing `503 upstream_unavailable`
- [x] 6.9 A request from an unseen IP carrying only `X-Client-Info` and no `key` succeeds on the first try
- [x] 6.10 Requests with `ts` skewed −2 hours, +2 hours and +10 minutes all succeed; a request with no `X-Client-Info` at all returns `401 unauthorized`, not a 500 or an empty 200
- [x] 6.11 50 searches in a minute from one IP are not rate limited; exceeding the limit returns `429` with `retry_after`
- [x] 6.12 `git diff --name-only` touches no path under `api/`, and `GET /api/fetch/posters` returns what it did before the change
- [x] 6.13 `grep -rn "getenv\|\$_ENV" marquee/` shows only `TMDB_API_KEY`, `FANART_API_KEY`, `MEDIUX_API_KEY` and `POSTERIA_API_KEY`
- [x] 6.14 Confirm the endpoint still serves when individual credentials are unset, marking those sources `skipped`

## 8. TheTVDB via the v4 API

Added after measuring the cost of omission (~20 posters per film, ~45 per show) and the owner approving one new environment variable.

- [x] 8.1 Add `TVDB_API_KEY` to the credential set as the single new environment variable; confirm the endpoint serves normally, marking TheTVDB `skipped`, when it is unset or rejected
- [x] 8.2 Add JSON POST support to the parallel HTTP client for the token exchange
- [x] 8.3 Implement `lib/tvdb.php`: `POST /login` token exchange cached for 20 days in the existing store, so login is not on the per-request path
- [x] 8.4 Locate film records by IMDb id via `/search/remoteid/{imdb_id}`, filtering results to the `movie` record type — a numeric TMDB id collides across providers (603 also matches the series "Veronica's Closet"), so the type filter is what prevents the wrong work's artwork being attached
- [x] 8.5 Locate show records from the `tvdb_id` TMDB already supplies, with no lookup call; fetch `/series/{id}/artworks?type=2`
- [x] 8.6 Locate season records via `/series/{id}/extended`, matching the requested season number against the `official` ordering only, then fetch `/seasons/{seasonId}/extended` artwork type 7; verify `season=0` Specials resolves
- [x] 8.7 Report TheTVDB as `no_data` for `type=collection`, which TheTVDB has no concept of
- [x] 8.8 Map artwork to poster objects: `image`→`url`, `thumbnail`→`thumb`, width/height carried, ISO 639-3 language normalised to 639-1, and **`score` deliberately not carried** because TheTVDB's runs in the hundred-thousands and would outrank every rated TMDB poster
- [x] 8.9 Issue the locate and artwork calls as two concurrent rounds so fanart still runs in parallel with TheTVDB's first call
- [x] 8.10 Verify provenance live: all 20 Matrix posters trace to TheTVDB record 169, season-2 art is season 2 only, and no title-derived slug is used anywhere

## 7. Report back

- [x] 7.1 Write the handoff summary Marquee's rewrite is gated on: verbatim endpoint URL; final request contract (`type` token set, whether `year` and `sources` are accepted, exact source token names); final response shape and `providers` vocabulary; the `X-Client-Info` payload including header name, accepted `name` value and the final `ts` tolerance; the final rate limits; the TheTVDB decision; the Mediux finding; the full error `code` list; confirmation that no new environment variable was added and anything wanted but not added; and confirmation that a first-time request from an unknown IP with no `key` succeeds
