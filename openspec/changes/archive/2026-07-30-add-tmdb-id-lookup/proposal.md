## Why

Title matching cannot reach a residual class of real library items, and no amount of tuning
will fix it. Measured against this repo's scorer, with a floor of 40:

```
"Spider-Noir B&W"            vs TMDB "Spider-Noir"                 22.00
"Breaking Bad El Camino"     vs TMDB "Breaking Bad"                22.00
"Ready or Not 2 Hear I Come" vs TMDB "Ready or Not: Here I Come"    2.00
```

The first is a local annotation that should match; the second is a distinct film that must
not. They score identically, through the same branch, so no threshold separates them — the
same trap that ruled out lowering the floor for collections. The third is unreachable by any
weight change at all.

Marquee now stores the TMDB id for its items. Since resolution's entire job is turning a title
into a `tmdb_id` — everything downstream keys off it — a supplied id makes that step
unnecessary and exact by construction. It also removes a TMDB search call from every request
that carries one.

The title stays in the request as a fallback, so an id that has gone stale in the client's
database degrades to today's behaviour rather than to an error.

## What Changes

- Accept an optional `tmdb_id` query parameter alongside the existing required `q`. When
  supplied, identify the work directly from it and skip title resolution entirely.
- Fall back to resolving `q` when, and only when, the supplied id does not identify a work —
  that is, when the provider answers `404` for it. A stale or wrong id then behaves exactly as
  a request without one.
- **Do not** fall back when the id identifies a work that simply has no artwork. That case is
  already specified as `200` with `posters: []`, and falling back there would gather a
  different work's posters and present them as this item's.
- Echo the requested `tmdb_id` in `query`, so a client can compare it against `match.tmdb_id`
  and detect that a fallback happened without needing `debug`.
- Report which path identified the work in the `debug` block.
- For `type=season`, `tmdb_id` is the **show's** id and is used together with `season=N`.
  There is no season-level id.

Explicitly **not** in this change: `suggestions` on the `no_match` body. It was considered
alongside this and deferred — it is a separate change with its own shape, and this one stands
on its own.

Title and id are never both used to gather artwork for one response. De-duplication keys on
image URL, so two different works share nothing and merging would concatenate both works'
posters into a grid the response shape cannot describe — `match` names exactly one work, and
posters deliberately carry no item-level metadata.

No breaking change. A request without `tmdb_id` behaves exactly as it does today.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `marquee-poster-search`: request parameters gain `tmdb_id`; a new requirement governs
  identification by id and the fallback to title; the response-shape requirement gains the
  `tmdb_id` echo in `query`; the debug requirement reports which path was taken.

## Impact

- `marquee/api/v1/lib/request.php` — parse and validate `tmdb_id`.
- `marquee/api/v1/posters/index.php` — branch identification on the supplied id, detect the
  `404` fallback trigger, echo the id, extend the debug block.
- `marquee/api/v1/tests/resolve_test.php` and `verify_live.sh` — cover the id path, the stale-id
  fallback, and the artless-work case that must not fall back.
- No change to `lib/resolve.php` scoring, no change to `RESOLVE_SCORE_FLOOR`, nothing under
  `api/`, no new environment variable.
- Client-side: Marquee sends `tmdb_id` in addition to `q`. Both remain valid without it.
