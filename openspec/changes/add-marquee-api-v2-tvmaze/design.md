## Context

`marquee/api/v1/` serves poster search from TMDB, fanart.tv and TheTVDB. TMDB is
both the resolution provider and a source; every other source is keyed on an
identifier only TMDB can supply. `lib/sources.php` issues the non-TMDB calls in up
to two concurrent rounds, because TheTVDB has to locate its own record before it can
be asked for artwork.

TVmaze was investigated against the live API before this design was written:

| Probe | Result |
| --- | --- |
| `GET /lookup/shows?thetvdb=81189` | `301` → `/shows/169`, full show object. No credential. |
| `GET /lookup/shows?imdb=tt0903747` | Same show. Unknown id → clean `404`. |
| `GET /shows/169/images` | 27 images, 26 typed `poster`, every one with `original` and `medium` renditions carrying `width`/`height` |
| `GET /shows/169/seasons` | 5 seasons, every one with an `image` and its own page `url` |
| Response headers | `access-control-allow-origin: *`, `cache-control: public, max-age=3600`. No rate-limit headers. |

Poster counts and dimension spread across three shows:

| Show | Posters | With `medium` | Distinct dimensions | `main: true` |
| --- | --- | --- | --- | --- |
| Breaking Bad (169) | 26 | 26 | 2 | 1 |
| Game of Thrones (82) | 45 | 45 | 7 | 1 |
| Under the Dome (1) | 14 | 14 | 4 | 1 |

Constraints carried in from `v1` and from the project:

- Nothing under `api/` may be touched, and now nothing under `marquee/api/v1/` either.
- Environment variables are added only by explicit owner decision. TVmaze needs none.
- A source that cannot return artwork in this deployment is removed, not advertised.
- The deployed PHP built-in server is serial, so a stuck upstream blocks the queue.

## Goals / Non-Goals

**Goals:**

- Serve `/marquee/api/v2/posters` and `/marquee/api/v2/time` with TVmaze as a fourth
  source, without modifying `v1`.
- Locate the TVmaze record only by identifier, never by title, preserving the
  provenance guarantee that motivates the whole endpoint.
- Satisfy TVmaze's CC BY-SA attribution obligation in the response contract, so a
  compliant client is possible without out-of-band knowledge.
- Introduce no new environment variable and no deployment step.

**Non-Goals:**

- MoviePosterDB. Its Data API at `movieposterdb.com/api/embed/v1` is live, well
  documented and identifier-keyed, but its billing model charges the key holder for
  every image its URLs deliver, and its terms forbid proxying images to avoid that.
  A single Posteria key would fund every self-hosted install's browsing; a per-install
  key contradicts `marquee-api-access`'s first requirement. Excluded by decision, not
  by discovery. See "Rejected: MoviePosterDB" below.
- A `not_applicable` provider outcome. Considered and declined: `v2` is scoped to
  adding a source, and widening the outcome vocabulary is a contract change the
  client would have to handle.
- Extracting shared libraries between `v1` and `v2`. The tree is copied, per the
  standing rule.

## Decisions

### Version by directory copy rather than by branching inside `v1`

`marquee/api/v2/` is a full copy of `marquee/api/v1/` with `lib/tvmaze.php` added.
`nixpacks.toml` runs `php -S -t .` from the repo root, so the copy is routable the
moment it exists — no router, no rewrite rule, no deployment change.

*Alternative considered:* add TVmaze to `v1` and let the new keys appear in
`providers`. Rejected — it changes the contract under running clients, and the
project has already paid for this exact containment once with `api/fetch/posters`.

*Alternative considered:* a shared `lib/` with a version shim. Rejected — it
recreates the coupling the "copy rather than extract" rule exists to prevent, and
would put the endpoint serving deployed clients at risk on every `v2` edit.

### TVmaze is located by TVDB id, falling back to IMDb id

`match.tvdb_id` is already resolved for fanart.tv's television endpoint, so the
TVDB id costs nothing extra and is tried first. `match.imdb_id` is the fallback for
shows TMDB has an IMDb id for but no TVDB id. If neither is available the source
reports `no_data`, exactly as fanart.tv and TheTVDB do.

`GET /search/shows?q=` is deliberately not used. It would let TVmaze answer for a
show the query did not name, which is the failure mode `marquee-poster-sources`
forbids in its "Provider record located by identifier" requirement.

### Two rounds, reusing the existing TheTVDB shape

TVmaze needs its own show id before it can be asked for images, so it slots into
`marqueeGatherExternalPosters()` alongside TheTVDB's lookup:

```
round 1 (concurrent)          round 2 (concurrent)
├── fanart.tv artwork         ├── thetvdb artwork
├── thetvdb locate            └── tvmaze images | seasons
└── tvmaze lookup ────────────────┘
```

`lib/http.php` already sets `CURLOPT_FOLLOWLOCATION` with `MAXREDIRS => 3`, so the
lookup's `301` resolves to the show object in one call and the show id is read from
its body. No redirect handling is added.

Round 2 is `/shows/:id/images` for `type=show` and `/shows/:id/seasons` for
`type=season`. Seasons come back as one list which is filtered to the requested
number in code, so a season request is still two calls, not three.

### Field mapping, and what is deliberately dropped

| v2 poster field | Show images | Season image |
| --- | --- | --- |
| `url` | `resolutions.original.url` | `image.original` |
| `thumb` | `resolutions.medium.url` | `image.medium` |
| `width` / `height` | `resolutions.original.width` / `.height` | omitted — not supplied |
| `source` | `tvmaze` | `tvmaze` |
| `page` | the show's `url` | the season's `url` |
| `language` | omitted — not supplied | omitted |
| `score` | omitted — see below | omitted |

Only entries with `type == "poster"` are taken. `banner`, `background` and
`typography` are not posters and are dropped.

TVmaze flags exactly one image per show as `main: true`. It is **not** carried. The
`v1` precedent is fanart.tv's like count, which was dropped rather than dressed up
as a `score` comparable to TMDB's vote average, and the same reasoning applies:
`main` is a designation, not a rating, and there is no honest scale to put it on.
The consequence is accepted — TVmaze posters carry no `score`, so they rank by pixel
area within their language bucket and the show's designated poster may land anywhere
in the list. Dimensions vary enough (2–7 distinct sizes per show) that area ranking
still does real work.

*Alternative considered:* a `primary: true` boolean plus a ranking tiebreak.
Rejected for `v2` — it adds a field to the poster contract that one source populates,
for a change scoped to adding a source.

### Attribution rides on the poster, not on the match

TVmaze data is CC BY-SA and requires a link back to the source. `page` is an optional
absolute URL on each poster object, alongside `url`, `thumb` and `source`.

Per-poster rather than a single block on `match` because a season poster's correct
link is the season page, not the show page — TVmaze publishes both, and they differ.
Putting the link next to the image it credits also means a client that renders a
subset of `posters` cannot accidentally drop the attribution for what it shows.

The field is optional and absent for TMDB, fanart.tv and TheTVDB, none of which
impose a comparable obligation. It generalises to any future source that does.

The `User-Agent` becomes `Posteria-Marquee-API/2.0`, which TVmaze's documentation
asks for so it can identify clients during an incident.

### Movies and collections report `no_data`

TVmaze indexes television only. A `type=movie` or `type=collection` request reports
`tvmaze: no_data` without issuing a call, the same treatment fanart.tv gets for
collections and TheTVDB gets for both in `v1`. No request is wasted on a question the
source cannot answer.

### Rejected: MoviePosterDB

Recorded because it was investigated in depth and the finding should not have to be
rediscovered.

`api.movieposterdb.com` is a dead legacy host — `521` on every path including
`/docs/`. The current API is `https://movieposterdb.com/api/embed/v1`, live, with a
published `openapi.json` (v1.2.0). It offers `GET /posters?imdb=…|?tmdb=…` and
`GET /movie?imdb=…|?tmdb=…`, returns `width`/`height`/`country_code`/`category` per
poster, supports `ETag`/`If-None-Match` where `304`s are unmetered, and explicitly
courts media-server integrations. It satisfies every provenance rule in
`marquee-poster-sources` without amendment.

It was excluded on economics, not on quality:

- Returned poster URLs are stamped with the caller's key, and **image delivery is
  metered against that key**. Free tier clamps URLs to `s_` (200 px) with 1 GB and
  10k requests a month — a 200 px poster is a thumbnail. XL sizes start at €15/year
  (Personal, non-commercial, one domain, 10 GB).
- One Posteria key would fund every self-hosted Marquee install's browsing worldwide,
  and "Personal / 1 domain" is unlikely to license redistribution to a user base.
- Proxying images through the Marquee server is explicitly forbidden — *"Don't proxy
  or re-host the images to dodge quotas — spike-detection flags this and the domain
  gets suspended"* — and would not reduce the meter anyway.
- A per-install key would move the cost to the beneficiary but contradicts
  `marquee-api-access`'s first requirement: no user-supplied credential, registration
  or per-install key.

Revisiting it needs a licensing conversation with MoviePosterDB, not a code change.

## Risks / Trade-offs

- **TVmaze rate limits on the server's IP, not on a key (~20 calls / 10 s).** Every
  Marquee install shares one quota, and there are no rate-limit headers to read.
  → The existing resolution and artwork caches (24 h TTL, keyed on the resolved work)
  absorb repeats, and TVmaze itself sends `cache-control: max-age=3600`. A `429` is
  reported as `error`, which is honest — it is transient and request-specific, unlike
  a rejected credential, which `v1` deliberately reports as `skipped`.

- **Two endpoint trees to maintain.** A bug fixed in `v2` is not fixed in `v1`.
  → Accepted, and intended: `v1` is frozen, not maintained. A defect serious enough
  to warrant patching `v1` is a decision to take at the time, not a standing policy.

- **`v1` and `v2` drift in the copied tree.** A reviewer cannot tell at a glance what
  differs.
  → The diff is deliberately small: `lib/tvmaze.php` is new, and `lib/config.php`,
  `lib/sources.php` and `lib/posters.php` carry the additions. `tests/` is copied and
  extended so `v2` is verifiable on its own.

- **A season yields one TVmaze image, not a set.** `total` for a season barely moves.
  → Accepted by decision. It is frequently artwork no other source carries, and one
  unique poster is worth more than none.

- **CC BY-SA is ShareAlike, not just attribution.** The obligation attaches to the
  data, and the `page` field only makes compliance *possible* — a client that ignores
  it is not compliant.
  → The handover to the Marquee client change states rendering `page` as a
  requirement, not a suggestion.

## Migration Plan

1. Copy `marquee/api/v1/` to `marquee/api/v2/`, updating `MARQUEE_API_V1` to
   `MARQUEE_API_V2`, the endpoint version constant, and the user agent.
2. Add `lib/tvmaze.php` and wire it into `lib/sources.php` and `lib/config.php`.
3. Add the optional `page` field to poster assembly.
4. Copy and extend `tests/`; verify `v2` against the live API.
5. Deploy. No environment change, no secret, no restart ordering.

**Rollback:** delete `marquee/api/v2/`. `v1` is untouched throughout and keeps
serving every deployed client, so a rollback costs nothing and strands no one.

## Open Questions

None blocking. Two deferred by decision and recorded so they are not re-litigated:

- Whether to carry TVmaze's `main` flag as a `primary` field, and rank on it.
- Whether `providers` should distinguish "this source cannot hold this media type"
  from "this source has nothing for this work" via a `not_applicable` outcome. It
  becomes more attractive as lopsided-coverage sources accumulate; TVmaze alone does
  not justify widening the vocabulary.
