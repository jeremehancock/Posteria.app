## Context

Posteria.app serves a poster-search API at `/api/fetch/posters` (`api/fetch/posters/index.php`, 1,272 lines). It is consumed by deprecated Posteria installs and by every currently deployed Marquee. It cannot be changed.

Its provider integrations are sound; its request orchestration is not. It hands TMDB's entire search-results page to a `foreach` and emits posters for every hit, so `?movie=The+Matrix` returns 683 posters of which 233 belong to the requested film, in a 429 KB payload. It scrapes TheTVDB HTML by title-derived slug with no verification that the page describes the same work. Season handling attaches show art to season results. Provider failures are swallowed at each call site.

This change builds a second, independent endpoint under `marquee/api/v1/` for Marquee to migrate onto. Marquee is the only consumer and will be rewritten against whatever contract is built, so no backward compatibility is required.

The deployment runs `php -S 0.0.0.0:$PORT -t .` from the repo root (`nixpacks.toml`), so routing is filesystem-based with `index.php` as the directory index, and there are no rewrite rules anywhere in the repo. The built-in server handles requests serially.

### Implementation reference — the existing file

Line references below are to `api/fetch/posters/index.php` as it stands. This section is a reading guide for the implementer; none of it describes required behaviour of the new endpoint.

**Copy (adapting as noted):**

| What | Lines | Note |
| --- | --- | --- |
| TMDB base URL, poster size map, image base | L19–31 | Reduce the four-size map to the two sizes the new shape uses |
| TMDB search / details / images endpoint set | L242–332 | `append_to_response=external_ids` at L292 is how `tvdb_id` is obtained; `include_image_language=en,null` at L316 stays |
| `HttpClient` parallel `curl_multi` wrapper | L46–122 | Sound; add per-request error capture and a timeout |
| fanart URL patterns | L405 (`/movies/{tmdbId}`), L429 (`/tv/{tvdbId}`) | Correct as written |
| `isValidClientInfo` | L162–193 | Copy the base64/JSON/name checks; replace the window logic — see Decision 6 |
| CORS headers | L5–10 | Keep permissive |
| `api/time.php` | whole file, 9 lines | Copy verbatim into the new tree |

**Do not carry over:**

| What | Lines | Why |
| --- | --- | --- |
| `foreach` over every TMDB search hit | L737 (movie), L824 (TV), L1083 (collection) | The accuracy defect; replaced by a resolution step |
| `getTvdbPosters` / `extractPosterUrls` | L474–590 | Title-slug HTML scraping with no provenance; replaced by the v4 API (Decision 5), not by nothing |
| `addSeasonInfoToItem` | L1191 | De-dupe argument omitted at L863 and L1003; invoked per-image at L927/L986/L1003 so show art is presented as season art |
| `extractSeasonNumber` / `cleanShowName` | L593–604 | The bare `s` in `/(season|s)\s*(\d+)/i` misfires; the new contract takes an explicit `season` |
| `findMovieByName` collection-fanart path | L606, L1139–1176 | Takes `results[0]` for `"<name> Collection"` unverified, and falls back to `parts[0]`, emitting an individual film's art as collection art |
| Four-identical-size-key formatters | L356, L366, L388 | Superseded by `url` + `thumb` |
| `include_all_posters` | L636 | Parsed, never used |
| `$responseCache` | L43 | Per-request only; the `cache` param at L1266 sets a header and nothing more |

## Goals / Non-Goals

**Goals:**

- One resolved work per request, with artwork for that work only.
- A response the client can render verbatim: flat, de-duplicated, ranked, capped, with a thumbnail per poster.
- Partial upstream failure visible in the response rather than silently absorbed.
- Any self-hosted install, at any address, works on first request with no credential.
- One deliberate configuration addition (`TVDB_API_KEY`), with the endpoint serving correctly before it is set.

**Non-Goals:**

- Fixing, tidying, or refactoring anything under `api/`.
- Extracting shared libraries between the two endpoints. Duplication is the intended tradeoff.
- Backward compatibility with the old request or response shape.
- Mediux artwork (Decision 8).
- HTML scraping of any provider. Artwork comes only from an API that identifies the work.
- Authentication in any real sense — the data is public poster art (Decision 6).
- Pagination. `limit` plus a true `total` is sufficient for a picker.

## Decisions

### 1. Path: `/marquee/api/v1/posters`

Filesystem routing means the URL is the directory layout:

```
marquee/api/v1/posters/index.php       →  GET /marquee/api/v1/posters
marquee/api/v1/time/index.php          →  GET /marquee/api/v1/time
marquee/api/v1/lib/*.php               →  internal, not routable as an endpoint
marquee/api/v1/tests/resolve_test.php  →  CLI only
```

The time endpoint is a directory with an `index.php`, not a bare `time.php`. The
built-in server resolves `index.php` as a directory index but does not append `.php`
to an extensionless path, so `time.php` would only ever answer at
`/marquee/api/v1/time.php` — confirmed by request, not assumed.

`/v1/` is included: it costs nothing now and avoids a second endpoint rename later. Marquee hard-codes this URL.

*Alternative considered:* flat `/marquee/api/posters`. Rejected — the versioned path buys a clean break for free.

*Note:* files under `lib/` are reachable as URLs under the built-in server. Each must be guarded so a direct request produces nothing useful (define-guard at the top, no top-level side effects).

### 2. Resolution before gathering

The single structural change from the old design. Order of operations per request:

1. Search TMDB for `q` in the endpoint corresponding to `type` (`/search/movie`, `/search/tv`, `/search/collection`; `season` searches TV).
2. Score every candidate and pick one. No candidate above the floor → `404 no_match`.
3. Fetch details for that one TMDB id (with `external_ids`) plus its `/images`.
4. Fan out to the other sources using the resolved identifiers.
5. Merge, de-duplicate, rank, cap.

Scoring, in decreasing weight: exact normalised-title match; `year` agreement (exact, then ±1 year); prefix/substring match; TMDB `popularity` as the tie-break. Normalisation lowercases, strips punctuation and diacritics, collapses whitespace, and drops a leading article. A minimum score floor prevents `Zzzznotarealtitle` from resolving to whatever TMDB returns first.

`year` is a hint, not a filter — a wrong year in Plex metadata must not produce `no_match` when the title matches exactly.

*Alternative considered:* returning a ranked candidate list and letting Marquee choose. Rejected — it pushes the disambiguation problem to every client and doubles the round trips for the common case where the answer is unambiguous.

### 3. Seasons as a first-class type

`type=season` resolves the show, then calls `/tv/{id}/season/{n}` for the season's identity and `/tv/{id}/season/{n}/images` for its posters. fanart's `seasonposter` entries are filtered to the requested season number.

There is nowhere in the response shape to put show art on a season request, which is the point: `posters` holds season art or it is empty. `season=0` is Specials and is a valid number throughout — every check must be `!== null`, never truthiness.

### 4. Response shape: `match` + flat `posters`

Item metadata is hoisted into `match` and appears once. Each poster carries `url`, `thumb`, `source`, and whatever of `width` / `height` / `language` / `score` the source supplies. TMDB's `/images` already returns all four and they are currently discarded.

Sizes: `url` → `original`, `thumb` → `w342`. fanart serves one size, so fanart posters omit `thumb`.

Ranking is a single deterministic sort: language match against `en` first, then `score` (TMDB `vote_average`, sources without a rating sorting below rated ones), then pixel area, then a stable tie-break on URL so repeated requests order identically. Default `limit` 200, maximum 500, `total` reported pre-limit. 200 rather than a rounder 100 because a correctly resolved film lands near 193 posters — the cap is there to stop a pathological work returning 639, not to truncate a normal result.

De-duplication is on the exact `url` string, applied after merge and before ranking.

### 5. TheTVDB via the v4 API, located by remote id

Included, using TheTVDB's v4 API under a new `TVDB_API_KEY` — the one environment variable this endpoint adds, approved by the owner rather than taken unilaterally.

**What the old scraper actually did.** Tracing its output changed the picture. It derives a slug from the title, fetches `thetvdb.com/movies/<slug>` and regex-scrapes image URLs out of the HTML. For The Matrix it produced 84 rows — but only **44 distinct images**, and the 60 rows titled `The Matrix` were **the same 20 images attached to three different works**: the 1999 film, a 2004 film also called "The Matrix", and an undated third. All 20 trace to TheTVDB record 169, which genuinely is The Matrix.

So the images were not wrong; the attachment was. Nothing verified that the scraped page described the work being searched for, and the fan-out then stapled one work's art onto two others. It happened to be right here because the slug resolved correctly, and there is no mechanism that would notice when it does not.

**The API removes the guess.** Records are located by remote id, never by title:

- **Movies:** `GET /search/remoteid/{imdb_id}` — the IMDb id comes from TMDB's details. Then `GET /movies/{id}/extended`, artwork type 14.
- **Shows:** the TVDB id is already in TMDB's `external_ids`, so no lookup at all. `GET /series/{id}/artworks?type=2`.
- **Seasons:** `GET /series/{id}/extended` to find the season record, then `GET /seasons/{seasonId}/extended`, artwork type 7.
- **Collections:** TheTVDB has no equivalent concept — `no_data`.

Three things this design must get right, each verified:

1. **Filter remote-id results by record type.** A remote-id lookup can return several records, because a numeric id collides across providers: searching the TMDB movie id `603` returns both the film *The Matrix* and the series *Veronica's Closet*. Without a record-type filter this reintroduces precisely the wrong-work bug the API was adopted to avoid. Movies are therefore looked up by IMDb id, which does not collide, and the record type is always required.
2. **Never carry TheTVDB's `score`.** It runs in the hundred-thousands (100003, 100142) and is not comparable to TMDB's 0–10 `vote_average`. Mapping it across would sort every TheTVDB poster above every TMDB one regardless of quality. Omitted, like fanart's like count.
3. **Normalise the language code.** TheTVDB reports ISO 639-3 (`eng`), TMDB 639-1 (`en`), and the two are compared during ranking. Unmapped codes pass through rather than being guessed at.

**Token handling.** `POST /login` exchanges the key for a bearer token valid about a month; it is cached in the same store as everything else for 20 days, so this is one extra round trip roughly every three weeks rather than one per request.

**Latency cost.** Movies and seasons need a locate call before the artwork call, so the fan-out becomes two concurrent rounds instead of one. Shows need only one, since TMDB already supplied the id.

**`tvdb_id` resolution was always kept** regardless of this decision: it comes from TMDB's `external_ids`, and fanart.tv's TV endpoint is keyed on it (`{fanartBaseUrl}/tv/{tvdbId}`).

**Degradation.** With `TVDB_API_KEY` unset or rejected, TheTVDB reports `skipped`, no response is marked `partial`, caching still works, and everything else serves normally — so the code can deploy before the variable is set.

*Alternative considered:* porting the scraper, which needs no variable. Rejected — it cannot verify provenance, and it is the source of the triple-attachment defect.

*Alternative considered:* scraping by id via `thetvdb.com/dereferrer/series/{id}`, which would fix provenance for TV without a new variable. Rejected once the variable was approved: it still parses HTML, and it does nothing for films, since TMDB does not carry a TheTVDB movie id.

### 6. Client identification, not authentication

Marquee is self-hosted at unknowable addresses and its source is public, so any secret shipped in it is public the moment it is committed. The endpoint is therefore open: any IP, no signup, no per-install key.

`X-Client-Info` is base64 JSON with `name` and `ts`, copied from L162–193 with one change: **the ±5-minute, not-in-the-future window becomes ±24 hours, future timestamps accepted.** The old window effectively requires an accurate clock, which Raspberry Pis without an RTC, NAS Docker hosts and resumed VMs do not have; each of those users gets a 401 that Marquee renders as "No posters found" — undiagnosable, with no route to a fix. Since the header is identification and not a secret (anyone can construct one), the narrow window buys nothing and costs real users.

±24 hours means Marquee can drop the `time.php` round trip entirely. `time.php` is still served for diagnostics.

`?key=` (matching `POSTERIA_API_KEY`) stays as an optional alternative and is never required. CORS stays `*`.

*Alternative considered:* dropping the `ts` check entirely. Equivalent in practice; the wide window is kept because it loosely bounds a replayed header at no cost.

### 7. Storage: filesystem, no configuration

Both the response cache and the rate-limit counters use APCu when the extension is loaded, falling back to files under `sys_get_temp_dir()`. No Redis, no Memcached, nothing with a connection string.

- **Cache key:** `type` + resolved TMDB id + season number + sorted `sources` + `limit`. Keying on the resolved work rather than the raw query means `the matrix` and `The Matrix` share an entry. TTL 24 hours. Responses carrying `code: partial` are not cached. Any cache read/write failure falls through to a live fetch — the cache is never load-bearing.
- **Rate limit:** fixed windows per IP, 60/minute and 3,000/hour, sized for one address being a household or a shared server. A counter-store failure serves the request. `429` carries `retry_after`.

The built-in server is serial and a second poster endpoint roughly doubles fan-out load on the same process, so the cache is doing real work here, not premature optimisation.

*Alternative considered:* skipping caching in v1. Rejected on the serial-server grounds above.

### 8. Mediux is dropped

The old code points at `https://staged.mediux.io` and swallows every failure, and Mediux has produced zero posters across every observed response while being credited in Marquee's UI. Probing settled why, and the answer is two independent faults:

- **`staged.mediux.io` is gone.** It answers `530`, Cloudflare error 1033 — origin unreachable. Every Mediux request the legacy endpoint has ever made failed at the network layer and was silently skipped. That alone explains the zero.
- **`api.mediux.pro` rejects the deployment's credential.** The production host is a live Directus instance; `MEDIUX_API_KEY` is a 32-character static token. It returns `401 INVALID_CREDENTIALS` on the `Authorization: Bearer` header, on `?access_token=`, and on `/users/me`. The token is expired, revoked, or was only ever valid for the dead staging host.

So Mediux cannot return artwork in this deployment, and a source that cannot contribute must not be advertised. It is omitted entirely: no `mediux` token, no `mediux.pro` in `providers`, no `MEDIUX_API_KEY` read. The endpoint reads three of the four permitted variables, which introduces nothing new.

Restoring it needs only a working credential — a value change, not a code change — plus re-adding the integration against `api.mediux.pro`, whose Directus query shape (`deep[files][_filter]`, per L410/L435/L443) is preserved in this document and in git history.

*Alternative considered:* keeping it wired up and letting it report its failure honestly. Rejected once measured: a source that fails on every request marks every response `partial`, and partial responses are never cached (Decision 7), so keeping it would have silently disabled caching endpoint-wide.

### 9. Failure reporting

Each source's fetch returns an explicit outcome rather than being tested for success at the call site and skipped. Outcomes: `ok`, `no_data`, `error`, `skipped`. All sources `error` → `503 upstream_unavailable`. Some → `200` with `code: partial`. This is what makes an accuracy regression diagnosable from the client: a fanart timeout stops looking identical to sparse art.

## Risks / Trade-offs

- **Duplicated provider code drifts from the original** → Intended. The two endpoints are independent by design; the old one is frozen, so there is no drift to reconcile.
- **Resolution picks the wrong work for genuinely ambiguous titles** (remakes, same-title different-year) → `year` disambiguates the common case; `debug=true` exposes the candidate list and the scores so a mis-resolution can be diagnosed rather than guessed at.
- **TheTVDB adds a second round trip on movie and season queries** → Bounded by the same per-request timeout as every other source, absorbed by the response cache on repeats, and the token is cached for 20 days so login is not on the hot path.
- **`TVDB_API_KEY` is a new operational dependency** → Degrades to `skipped` when absent or rejected, so a missing or expired key is a quiet loss of one source rather than an outage.
- **A resolution bug now returns nothing rather than too much** → A false `no_match` is more visible than a padded result list, and the `debug` output makes it diagnosable. Preferred over silently wrong output.
- **Files under `marquee/api/v1/lib/` are URL-reachable** under the built-in server → Define-guard each and keep all logic in functions and classes with no top-level side effects.
- **A 24-hour timestamp window accepts a replayed header** → Accepted. The header is not a secret and the data is public poster art; the only real exposure is provider quota, which the rate limiter covers.
- **Doubled fan-out on a serial PHP server** → The response cache absorbs repeat searches; per-request upstream timeouts bound the worst case so one slow provider cannot block the queue.
- **APCu absent in production** → The filesystem fallback is the assumed path, not a degraded one; APCu is used only if present.

## Migration Plan

The new endpoint is purely additive. Deployment is a normal push: nixpacks serves the new tree on the next deploy with no configuration change.

There is no dead window and no forced breakage — deployed Marquee installs keep calling `/api/fetch/posters` until they are updated, and that endpoint is untouched.

Rollback is deleting the `marquee/` tree; nothing else depends on it.

Verification before reporting done:

- `git diff --name-only` touches no path under `api/`, and `GET /api/fetch/posters` behaves as before.
- `grep -rn 'getenv\|\$_ENV' marquee/` shows only the four permitted variable names.
- `?q=The+Matrix&type=movie&year=1999` → only the 1999 film, ~193 posters, under 100 KB.
- `?q=The+Matrix&type=movie` (no year) → still no Reloaded / Revolutions / Resurrections.
- `?q=Breaking+Bad&type=show` → no `The Bad Guys: The Series`.
- `?q=Breaking+Bad&type=season&season=2` → season-2 art only, zero show posters, no duplicate URLs.
- `?q=Breaking+Bad&type=season&season=0` → Specials art.
- `?q=Star+Wars+Collection&type=collection` → no LEGO / Robot Chicken / Ewok entries, no individual film's art.
- `?q=Zzzznotarealtitle&type=movie` → `404 no_match`.
- fanart TV posters still return for `type=show` and `type=season`, confirming `tvdb_id` resolution survived the TVDB removal.
- No duplicate `url` in any response; every `url` resolves; `thumb` present wherever the source allows.
- First request from an unseen IP with only `X-Client-Info` and no `key` succeeds.
- `ts` skewed −2h, +2h and +10min all succeed; no `X-Client-Info` at all gives `401 unauthorized`, not a 500 or an empty 200.
- 50 searches in a minute from one IP are not limited; when a limit is hit the response is `429` with `retry_after`.

## Open Questions

These are decided as stated above and implementation proceeds on them; they are listed because Marquee's rewrite is gated on the answers and the owner may overrule.

1. **TheTVDB** — included via the v4 API, adding `TVDB_API_KEY`. Decided after measuring the cost of omission: ~20 posters per film, ~45 per show.
2. **Mediux** — dropped. Its former host is unreachable and the deployment's credential is rejected by its current host (Decision 8). Marquee must stop crediting it.
3. **`type=show` not `type=tv`** — matches Marquee's own `PlexMediaType` vocabulary so neither side needs a translation layer.
4. **`/v1/` in the path** — included.
5. **Timestamp tolerance ±24 hours** — determines whether Marquee keeps the `time.php` round trip. At this tolerance it should drop it.
6. **Rate limits 60/min, 3,000/hour per IP** — stated so Marquee can respect them rather than discover them.
