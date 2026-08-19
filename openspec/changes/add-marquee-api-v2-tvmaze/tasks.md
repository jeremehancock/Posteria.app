## 1. Stand up the v2 tree

- [ ] 1.1 Copy `marquee/api/v1/` to `marquee/api/v2/` verbatim, then confirm with `diff -r` that the only differences are the ones introduced below
- [ ] 1.2 Rename the include guard from `MARQUEE_API_V1` to `MARQUEE_API_V2` in every file that defines or checks it
- [ ] 1.3 Set `MARQUEE_ENDPOINT_VERSION` to `v2` and `MARQUEE_USER_AGENT` to `Posteria-Marquee-API/2.0` in `lib/config.php`
- [ ] 1.4 Verify `GET /marquee/api/v2/posters?q=The+Matrix&type=movie&year=1999` and `GET /marquee/api/v2/time` serve identically to `v1` before any source is added
- [ ] 1.5 Confirm nothing under `marquee/api/v1/` or `api/` was touched, and that `grep -rn "api/v1" marquee/api/v2/` returns nothing

## 2. TVmaze client

- [ ] 2.1 Add TVmaze constants to `lib/config.php`: base URL `https://api.tvmaze.com`, and the poster image type token. No environment variable.
- [ ] 2.2 Add `tvmaze` to `VALID_SOURCE_TOKENS` and to `SOURCE_LABELS` (label `tvmaze`)
- [ ] 2.3 Create `lib/tvmaze.php` with a lookup-URL builder that prefers the TVDB identifier and falls back to the IMDb identifier, returning null when neither is available
- [ ] 2.4 Add a mapper for `/shows/:id/images` that keeps only entries typed `poster` and emits `url`, `thumb`, `width`, `height`, `source` and `page`
- [ ] 2.5 Add a mapper for `/shows/:id/seasons` that selects the requested season by `number` and emits `url`, `thumb`, `source` and `page` from the season's own `image` and `url`
- [ ] 2.6 Confirm neither mapper carries `main`, `language` or `score`, and that absent fields are omitted rather than nulled

## 3. Wire TVmaze into the gather step

- [ ] 3.1 In `lib/sources.php`, report `tvmaze` as `skipped` when excluded by `sources`, and as `no_data` without a call when `type` is `movie` or `collection`
- [ ] 3.2 Report `tvmaze` as `no_data` without a call when neither the TVDB nor the IMDb identifier resolved
- [ ] 3.3 Issue the identifier lookup in round one alongside fanart.tv and TheTVDB's locate call
- [ ] 3.4 Treat a `404` from the lookup as `no_data`, not `error`; read the show id from the redirected response body
- [ ] 3.5 Issue `/shows/:id/images` (show) or `/shows/:id/seasons` (season) in round two, alongside TheTVDB's artwork call
- [ ] 3.6 Report `ok` when posters were returned, `no_data` when none were, and `error` on timeout, `429`, or an unusable response
- [ ] 3.7 Record every TVmaze call in the debug `calls` list with its stage, status and timing, matching the shape the other sources use

## 4. Response contract

- [ ] 4.1 Carry the optional `page` field through de-duplication, ranking and limiting in `lib/posters.php` without altering the ranking order
- [ ] 4.2 Confirm posters from TMDB, fanart.tv and TheTVDB omit `page` entirely
- [ ] 4.3 Confirm `total` counts TVmaze posters and that de-duplication still keys on the image URL

## 5. Tests

- [ ] 5.1 Copy `tests/` into the `v2` tree and repoint every path from `v1` to `v2`
- [ ] 5.2 Add live checks: `type=show` returns TVmaze posters with `page` set; `type=season` returns the season image linked to the season page, not the show page
- [ ] 5.3 Add live checks: `type=movie` and `type=collection` report `tvmaze: no_data` with no TVmaze call in debug output, and the response is not `partial`
- [ ] 5.4 Add a check that `sources=tvmaze` reports the other three as `skipped` and still returns posters for a show
- [ ] 5.5 Add a check that a show with no TVDB identifier is still located through the IMDb identifier
- [ ] 5.6 Add a regression check that `v1` responses contain no `tvmaze` key and no `page` field

## 6. Verify and hand over

- [ ] 6.1 Run the `v2` suite against the live API and record the outcome
- [ ] 6.2 Re-run the `v1` suite unchanged and confirm it still passes
- [ ] 6.3 Confirm `grep -rn "getenv\|\$_ENV" marquee/api/v2/` lists the same four variables `v1` reads and no others
- [ ] 6.4 Spot-check ordering stability: issue an identical show request twice and diff the poster arrays
- [ ] 6.5 Hand the client-side proposal to the Marquee app session
