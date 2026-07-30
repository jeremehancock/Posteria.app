## Why

Marquee's poster picker is fed by an endpoint that returns every TMDB search hit rather than the requested title, so a search for one film returns hundreds of posters belonging to sequels, documentaries and unrelated works, in a payload large enough to be slow to render. That endpoint also serves deprecated Posteria installs and every currently deployed Marquee, so it cannot be changed. This change adds a separate, correct poster API for Marquee to migrate onto, leaving the existing one untouched.

## What Changes

- Add a new poster search endpoint at `GET /marquee/api/v1/posters` that resolves the query to exactly one work before gathering any art, and returns posters for that work only.
- Add `GET /marquee/api/v1/time` — a standalone server-time endpoint for diagnostics, which clients are never required to call.
- Introduce a request contract keyed on `q` + `type` (`movie` | `show` | `season` | `collection`), with `season` promoted to a first-class type rather than a modifier, plus optional `year`, `limit`, `sources` and `debug`.
- Introduce a flat response shape: one `match` object carrying the resolved item's identity, one de-duplicated `posters` array carrying `url` + `thumb` per image with `source`, dimensions, language and score, a true `total`, and a `providers` outcome map.
- Rank posters deterministically best-first and apply a default cap, so the client can render the server's order verbatim.
- Report per-provider outcomes explicitly (`ok` / `error` / `skipped` / `no_data`) so partial upstream failure is distinguishable from sparse art.
- Return machine-readable error codes (`no_match`, `unauthorized`, `rate_limited`, `partial`, `upstream_unavailable`) with correct HTTP statuses.
- Serve any self-hosted client from any IP with no user-supplied credential: `X-Client-Info` identifies the client for logging with a skew-tolerant timestamp, `?key=` stays optional, CORS stays permissive, and rate limiting acts as a throttle that never gates a first request.
- Add server-side caching keyed on the resolved item, using only the filesystem or APCu.
- Add TheTVDB as a poster source via its v4 API, locating records by remote id (IMDb for films, the TVDB id TMDB already supplies for shows) rather than by a title-derived slug, so artwork provenance is verifiable. This introduces `TVDB_API_KEY` — the one new environment variable, added by explicit decision.
- Keep TMDB's `tvdb_id` resolution, which fanart.tv's TV endpoint also depends on.
- Omit Mediux as a poster source: its former host is unreachable and the deployment's credential is rejected by its current host, so it can only be advertised and never contribute. TMDB, fanart.tv and TheTVDB are the three sources.
- Duplicate the provider integration code the new endpoint needs rather than extracting shared libraries from the existing one.

Not a breaking change: the existing `GET /api/fetch/posters` keeps its current behaviour and is not modified. Deployed Marquee installs continue calling it until they are updated.

## Capabilities

### New Capabilities
- `marquee-poster-search`: the `/marquee/api/v1/posters` request contract, single-item resolution, response shape, poster ordering, limits, caching, and error codes.
- `marquee-poster-sources`: per-provider poster gathering behaviour (TMDB, fanart.tv, TheTVDB), cross-provider identifier resolution, source selection, and the `providers` outcome map.
- `marquee-api-access`: client identification via `X-Client-Info`, optional `?key=` access, CORS, rate limiting, and the `/marquee/api/v1/time` endpoint.

### Modified Capabilities

None. No specs exist yet, and the legacy endpoint's behaviour is deliberately left unspecified and unchanged.

## Impact

- **New code**: a `marquee/api/v1/` tree containing `posters/index.php`, `time/index.php`, and provider client code (TMDB client, fanart fetcher, TheTVDB v4 client, parallel `curl_multi` wrapper, poster formatters).
- **Untouched**: everything under `api/`. No file there is edited, and no shared library is extracted from it.
- **Configuration**: one addition. The endpoint reads `TMDB_API_KEY`, `FANART_API_KEY`, `POSTERIA_API_KEY` and the new `TVDB_API_KEY`. `MEDIUX_API_KEY` goes unread because Mediux is not a source here. Until `TVDB_API_KEY` is set, TheTVDB reports `skipped` and everything else serves normally, so deployment does not have to be sequenced with the configuration change. Caching and rate-limit counters use the filesystem or APCu so no connection string is needed.
- **Runtime**: the deployed PHP built-in server handles requests serially, and a second poster endpoint roughly doubles fan-out load on the same process, which is why server-side caching is in scope here rather than deferred.
- **Consumers**: Marquee only, which will be rewritten against the new contract. No backward compatibility is required.
- **Owner decision taken**: TheTVDB art is included, and `TVDB_API_KEY` added, after measuring that omitting it costs roughly 20 posters per film and 45 per show.
