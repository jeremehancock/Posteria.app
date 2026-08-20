## 1. Stand up the v2 tree

- [x] 1.1 Copy `marquee/api/v1/` to `marquee/api/v2/` verbatim, then confirm with `diff -r` that the only differences are the ones introduced below
- [x] 1.2 Rename the include guard from `MARQUEE_API_V1` to `MARQUEE_API_V2` in every file that defines or checks it
- [x] 1.3 Set `MARQUEE_ENDPOINT_VERSION` to `v2` and `MARQUEE_USER_AGENT` to `Posteria-Marquee-API/2.0` in `lib/config.php`
- [x] 1.4 Verify `GET /marquee/api/v2/posters?q=The+Matrix&type=movie&year=1999` and `GET /marquee/api/v2/time` serve identically to `v1` before any source is added
- [x] 1.5 Confirm nothing under `marquee/api/v1/` or `api/` was touched, and that `grep -rn "api/v1" marquee/api/v2/` returns nothing
- [x] 1.6 Namespace the store on the endpoint version. Not in the original plan: the artwork cache key is identical between versions for a given work and `sources` list, so a shared APCu prefix or temp directory would serve a `v2` payload carrying `tvmaze` to a `v1` client

## 2. TVmaze client

- [x] 2.1 Add TVmaze constants to `lib/config.php`: base URL `https://api.tvmaze.com`, and the poster image type token. No environment variable.
- [x] 2.2 Add `tvmaze` to `VALID_SOURCE_TOKENS` and to `SOURCE_LABELS` (label `tvmaze`)
- [x] 2.3 Create `lib/tvmaze.php` with a lookup-URL builder that prefers the TVDB identifier and falls back to the IMDb identifier, returning null when neither is available
- [x] 2.4 Add a mapper for `/shows/:id/images` that keeps only entries typed `poster` and emits `url`, `thumb`, `width`, `height`, `source` and `page`
- [x] 2.5 Add a mapper for `/shows/:id/seasons` that selects the requested season by `number` and emits `url`, `thumb`, `source` and `page` from the season's own `image` and `url`
- [x] 2.6 Confirm neither mapper carries `main`, `language` or `score`, and that absent fields are omitted rather than nulled

## 3. Wire TVmaze into the gather step

- [x] 3.1 In `lib/sources.php`, report `tvmaze` as `skipped` when excluded by `sources`, and as `no_data` without a call when `type` is `movie` or `collection`
- [x] 3.2 Report `tvmaze` as `no_data` without a call when neither the TVDB nor the IMDb identifier resolved
- [x] 3.3 Issue the identifier lookup in round one alongside fanart.tv and TheTVDB's locate call
- [x] 3.4 Treat a `404` from the lookup as `no_data`, not `error`; read the show id from the redirected response body
- [x] 3.5 Issue `/shows/:id/images` (show) or `/shows/:id/seasons` (season) in round two, alongside TheTVDB's artwork call
- [x] 3.6 Report `ok` when posters were returned, `no_data` when none were, and `error` on timeout, `429`, or an unusable response
- [x] 3.7 Record every TVmaze call in the debug `calls` list with its stage, status and timing, matching the shape the other sources use

## 4. Response contract

- [x] 4.1 Carry the optional `page` field through de-duplication, ranking and limiting in `lib/posters.php` without altering the ranking order
- [x] 4.2 Confirm posters from TMDB, fanart.tv and TheTVDB omit `page` entirely — *superseded by group 7, which gives every source a `page` and moves the licence distinction onto `attribution_required`*
- [x] 4.3 Confirm `total` counts TVmaze posters and that de-duplication still keys on the image URL

## 5. Tests

- [x] 5.1 Copy `tests/` into the `v2` tree and repoint every path from `v1` to `v2`
- [x] 5.2 Add live checks: `type=show` returns TVmaze posters with `page` set; `type=season` returns the season image linked to the season page, not the show page
- [x] 5.3 Add live checks: `type=movie` and `type=collection` report `tvmaze: no_data` with no TVmaze call in debug output, and the response is not `partial`
- [x] 5.4 Add a check that `sources=tvmaze` reports the other three as `skipped` and still returns posters for a show
- [x] 5.5 Add a check that a show with no TVDB identifier is still located through the IMDb identifier
- [x] 5.6 Add a regression check that `v1` responses contain no `tvmaze` key and no `page` field
- [x] 5.7 Extend the unit suite with TVmaze coverage: lookup-URL preference, image-type filtering, season selection, `page` presence, and the fields deliberately not carried. 143 pass, 0 fail

## 6. Verify and hand over

- [x] 6.1 Run the `v2` suite against the live API — **120 pass, 0 fail** with all three credentials set. Covers the TVmaze block, the TheTVDB provenance block, provider outcomes under a rejected credential, and the `v1`-is-unaffected block
- [x] 6.2 Re-run the `v1` suite unchanged and confirm it still passes — `resolve_test.php` 106 pass, 0 fail; `verify_live.sh` **91 pass, 0 fail**
- [x] 6.3 Confirm `grep -rn "getenv\|\$_ENV" marquee/api/v2/` lists the same four variables `v1` reads and no others
- [x] 6.4 Spot-check ordering stability — 200 shuffled inputs at unit level including an equal-area tie, plus the live two-request diff in the suite
- [ ] 6.5 Hand the client-side proposal to the Marquee app session — do this only after `v2` is deployed and answering
- [x] 6.6 Exercise `marqueeGatherExternalPosters()` directly against live TVmaze, which needs no credential. All nine branches confirmed: show via TVDB id, show via IMDb fallback, no identifier, unknown identifier (`404` → `no_data`), season, absent season, movie, collection, and exclusion by `sources`

## 7. Universal source links

Added after the source work landed: probing showed all four sources can be addressed
with no extra call and no title-derived slug, so `page` is carried by every source and
`attribution_required` marks the one case where rendering it is a licence term.

- [x] 7.1 Add web base URL constants for TMDB, fanart.tv and TheTVDB to `lib/config.php`
- [x] 7.2 Add `marqueeTmdbPage()` building `/movie/{id}`, `/tv/{id}`, `/tv/{id}/season/{n}` and `/collection/{id}`, and carry the result on every TMDB poster
- [x] 7.3 Add a fanart.tv page builder using the TMDB id for films and the TVDB id for television — the same identifiers already used to call its API — and carry it on every fanart poster
- [x] 7.4 Capture TheTVDB's `slug` from the payloads already fetched (series artworks, movie extended, series extended on the season path) and carry `/series/{slug}`, `/movies/{slug}` or `/series/{slug}/seasons/official/{n}` on every TheTVDB poster
- [x] 7.5 Omit `page` rather than guess when a source's identifier or slug is unavailable
- [x] 7.6 Add `attribution_required: true` to TVmaze posters only, omitted entirely elsewhere
- [x] 7.7 Extend the unit suite: page built per type for each source, absent when the identifier is missing, and the marker present only on TVmaze
- [x] 7.8 Extend the live suite: every poster from every source carries a resolvable `page`, season links differ from show links, and only TVmaze is marked
- [x] 7.9 Re-run both live suites and confirm `v1` still carries neither field
- [x] 7.10 Update `client-handover.md` — `page` is now universal and `attribution_required` is the field the client keys its obligation on
