## Context

`posters/index.php` runs: resolve → artwork cache → gather → assemble. Resolution's sole
output is a `tmdb_id`; everything after it keys off `$winner['tmdb_id']`, and
`marqueeTmdbWorkDetails($type, $tmdbId, $key)` already takes that id and nothing else. So a
client-supplied id can enter the pipeline at exactly the point resolution would have produced
one, and every downstream stage runs unmodified.

The artwork cache is already keyed on the resolved work —
`posters:<type>:<tmdb_id>:<season>:<sources>:<limit>` — not on the query. An id request and a
title request that land on the same work therefore already share a cache entry, with no
change needed.

`marqueeResolveWork()` is untouched by this change. Nothing about scoring, the floor, or the
collection-suffix handling moves.

## Goals / Non-Goals

**Goals:**

- A supplied `tmdb_id` identifies the work exactly, skipping title resolution and its search
  call entirely.
- An id that has gone stale in the client's database degrades to today's behaviour rather than
  to an error.
- A client can tell a fallback happened without turning on debug.
- Requests without `tmdb_id` are bit-for-bit unchanged.

**Non-Goals:**

- `suggestions` on the `no_match` body. Deferred to its own change.
- Any change to scoring, `RESOLVE_SCORE_FLOOR`, or the collection-suffix normalisation.
- Accepting `imdb_id` or `tvdb_id` as entry points. TMDB is the resolution provider and the
  only one whose id every downstream source can be keyed from; the others are outputs.
- Making `q` optional. See decision 3.

## Decisions

### 1. The id enters where resolution would have left off

On a supplied id, skip `marqueeTmdbSearch()` and `marqueeResolveWork()` and synthesise the
same structure the resolver returns:

```php
$winner = ['tmdb_id' => $request['tmdb_id'], 'title' => $request['q'], 'year' => null, 'score' => null];
```

`marqueeBuildMatch()` then overwrites `title` and `year` from the provider's details payload,
exactly as it does for a resolved winner — so `match` ends up describing the real work rather
than echoing the client's local title. The client's title is used only as a placeholder for
the window between synthesising the winner and receiving details.

*Alternative rejected:* a separate id-only code path that duplicates the gather-and-assemble
sequence. It would double the surface that season handling, provider outcomes and caching have
to be correct on, for no benefit — the existing path already takes an id.

### 2. The fallback triggers on a definitive `404` and nothing else

`marqueeTmdbWorkDetails()` returns per-call `status`. A `404` on `details` means the provider
does not have that id: that is the stale-id case, and the request restarts down the title path.

Any other failure — timeout, 5xx, network — is *not* a fallback trigger. The id may be
perfectly good and the provider merely unreachable; switching to a title search there could
silently return a different work's artwork during an outage. Those failures continue through
the existing provider-outcome handling, which reports them honestly as `error` or
`upstream_unavailable`.

The cost of the fallback is a second round of TMDB calls, paid only on the rare bad-id path.

### 3. Never fall back because a work has no artwork

This is the decision most likely to be misread as a bug later, so it is worth stating in full.

"The id returned nothing" has two meanings, and only one of them is a bad id:

| Case | Meaning | Behaviour |
| --- | --- | --- |
| Provider `404`s the id | The id is wrong or stale | Fall back to the title |
| Id resolves, work has no posters | The id is right; the work is artless | `200`, `posters: []` |

Falling back in the second case would issue a title search whose results belong to a
*different* work and present them under this item's `match`. It would also contradict the
existing requirement that a resolved work with genuinely no artwork is a success rather than a
failure. The distinction is load-bearing and gets its own test.

### 4. `q` stays required

The fallback only exists if a title is always present, and making `q` conditional would add a
validation branch for a case the client has no reason to want — Marquee holds both. Keeping
`q` required also means logging and the `query` echo are unchanged in shape, and a request that
adds `tmdb_id` to an existing call is purely additive.

### 5. The fallback is visible without debug

`query.tmdb_id` reports what the client asked for; `match.tmdb_id` reports what the response
describes. When they differ, a fallback happened. No new top-level key, no parsing of `debug`,
and it costs one field in an object that already exists to echo the interpreted request.

This matters because a silent fallback is the failure mode to avoid: Marquee would show correct
artwork for the wrong work and have no way to know its stored id had rotted. Surfacing it lets
the client repair its own database.

*Alternative rejected:* a top-level `identified_by` field. It would change the "exactly these
top-level keys" contract for information already derivable from two fields that must be present
anyway.

### 6. Season keeps the show's id

There is no season-level TMDB id — `marqueeTmdbSeason($showId, $seasonNumber, $key)` takes the
show id and a number. So for `type=season`, `tmdb_id` is the show's, and `season` still carries
the number. This is the one place the parameter's meaning depends on `type`, and it needs to be
unambiguous in the client contract or Marquee will send the wrong thing.

## Risks / Trade-offs

- **A wrong-but-valid id returns confidently wrong artwork** → The largest risk, and it is
  inherent to trusting a client identifier: if Marquee stores id 603 for the wrong item, the
  API cannot detect it, because 603 is a real work. Mitigated only by the id coming from Plex's
  own metadata rather than from matching. Worth stating plainly to the client team: the id path
  moves correctness upstream to whatever populated it.
- **Fallback doubles upstream calls on a bad id** → Bounded to the stale-id path, which should
  be rare, and cheaper than the alternative of failing the request outright.
- **`year` becomes silently inert with an id** → Specified rather than left implicit. It is
  already inert for collections, so this is a second instance of an existing shape.
- **Trade-off: the endpoint now has two ways to identify a work.** That is a genuine increase in
  surface. It is accepted because the title path cannot be fixed for the residual class — the
  measurements in the proposal show two structurally identical inputs needing opposite outcomes
  — and an exact identifier is the only thing that sidesteps it.

## Migration Plan

Additive and independently deployable. The API can ship first and ignore an absent `tmdb_id`;
Marquee can start sending it whenever it is ready, with no coordination window and no version
negotiation. Rollback is reverting the commit; any client already sending `tmdb_id` degrades to
having the parameter ignored, which is today's behaviour.

No cache invalidation: the artwork cache is keyed on the resolved work, and the resolution cache
is only consulted on the title path, which is unchanged.

## Open Questions

- Whether Plex exposes usable TMDB ids for **collections**. They are a local Plex construct, so
  probably not, in which case collections stay on the title path permanently and the
  collection-suffix fix remains the whole answer for them. This does not block the change —
  Marquee simply omits `tmdb_id` where it has none.
