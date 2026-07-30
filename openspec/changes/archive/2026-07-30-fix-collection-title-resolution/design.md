## Context

`marqueeResolveWork()` in `marquee/api/v1/lib/resolve.php` scores every TMDB search hit and
returns the top candidate only if it clears `RESOLVE_SCORE_FLOOR` (40, `lib/config.php:81`).
`marqueeScoreCandidate()` awards 60 for an exact normalised-title match, 25 for a candidate
that starts with the query plus a space, 20 for the reverse, 15 for whole-word containment,
plus at most 5 for popularity.

TMDB names every collection record `"<Franchise> Collection"`. Plex does not, and Marquee sends
the Plex title verbatim. So for `q=Star Wars` the exact branch misses, the prefix branch fires,
and the winner scores 30 — rejected. Reproduced against the checked-in scorer:

```
Star Wars            vs Star Wars Collection       30.00  -> 404
Alien                vs Alien Collection           29.00  -> 404
Harry Potter         vs Harry Potter Collection     29.50  -> 404
The Matrix           vs The Matrix Collection       28.00  -> 404
```

Case folding and leading-article stripping already work (`marqueeNormaliseTitle()` handles
`matrix collection` correctly). Only the suffix is unhandled.

Two facts constrain the fix, both measured rather than assumed:

**The floor cannot move.** Sequels are prefix matches too, and they land in the same band as
the collection matches we want to admit — `The Matrix Reloaded` 28.00, `The Matrix
Resurrections` 27.75, `Alien Resurrection` 27.00, `Star Wars Holiday Special` 26.00, against
collections at 28–30. There is no floor value between them. Dropping the floor to 30 to rescue
`Star Wars` also admits `The Matrix → The Matrix Reloaded`, which is the exact failure the
comment at the top of `resolve.php` says the resolver exists to prevent. Raising the prefix
weight from 25 is the same knob and fails the same way.

**The year hint is a no-op for collections.** `marqueeCandidateFacts()` sets `$date = null` for
`type === 'collection'`, so `$facts['year']` is always null and the year branch in
`marqueeScoreCandidate()` never fires. Verified: `Star Wars` vs `Star Wars Collection` scores
30.00 both with and without `year=1977`. This corrects the handoff's §8 note that a matching
year could add +20 and make the bug look intermittent — it cannot. Every collection query
fails uniformly, and there is no separate year-path failure mode to fix.

Secondly, `debug=true` produces no `debug` key on a 404, because `marqueeResolveWork()` returns
a bare `null` and discards the scored candidates, and `posters/index.php:80-85` calls
`marqueeSendFailure()` with no extra fields. The 404 is the case where the scores matter most.

## Goals / Non-Goals

**Goals:**

- `type=collection` resolves a media-server collection title to the provider's record of the
  same collection, with or without the ` Collection` suffix, in any case.
- Movie, show and season resolution is bit-for-bit unchanged.
- `debug=true` on a `no_match` 404 shows the normalised query, the floor, and every candidate's
  score, so the next bug of this shape is one request to diagnose rather than a scorer read.
- The fixture suite gains cases in Plex's vocabulary, which is the only vocabulary Marquee
  sends.

**Non-Goals:**

- Changing `RESOLVE_SCORE_FLOOR` or any scoring weight. Ruled out by measurement above.
- Any form of fuzzy, edit-distance or generalised trailing-token matching. Stripping
  `Reloaded` from `The Matrix Reloaded` makes it match the 1999 film at 60 — self-defeating.
- Making custom Plex collections ("Marvel Cinematic Universe", "Christmas Movies") resolve.
  They have no upstream record; their 404 is correct.
- Locally annotated variants (`Spider-Noir B&W`). Handled client-side with an editable query.
- Any change under `api/`, to `lib/config.php` constants, or to the environment set.

## Decisions

### 1. A type-aware normalisation wrapper, leaving `marqueeNormaliseTitle()` untouched

Add a second function that calls `marqueeNormaliseTitle()` and then, for `type === 'collection'`
only, removes a trailing `collection` token:

```php
function marqueeNormaliseTitleForType(string $title, string $type): string
{
    $normalised = marqueeNormaliseTitle($title);

    if ($type !== 'collection') {
        return $normalised;
    }

    $stripped = preg_replace('/\s+collection$/', '', $normalised) ?? $normalised;

    // A collection actually named "Collection" would otherwise normalise to nothing
    // and match every candidate.
    return $stripped === '' ? $normalised : $stripped;
}
```

The regex runs against the already-normalised string, so it is lowercase, punctuation-free and
single-spaced — no case or separator variants to enumerate. `\s+…$` requires a preceding word
and anchors to the end, which satisfies both scoping constraints at once: *The Criterion
Collection Presents…* keeps its mid-string occurrence, and `The Collection` (2012) is a movie
so the branch never fires for it.

*Alternatives rejected:*

- **Modify `marqueeNormaliseTitle()` directly.** It is shared with movie, show and season
  scoring and with the cache key; three fixture assertions pin its behaviour. A global strip
  would break `The Collection` as a movie title.
- **Strip inside `marqueeCandidateFacts()`.** `$facts['titles'][0]` doubles as
  `$facts['title']`, which `marqueeBuildMatch()` uses as the response's display title when TMDB
  details are unavailable. Mutating it would report the collection as `Star Wars` rather than
  `Star Wars Collection`.

### 2. Reach the exact branch rather than adding a scoring band

The strip makes the suffix invisible on **both** sides, so `star wars` === `star wars` and the
exact branch awards 60. This is the point: a new "suffix match" branch scoring somewhere between
25 and 40 would have to sit above the sequel band to clear the floor, and there is no room there
— that is the same failed knob in a new shape. Landing on 60 also means the collection outranks
any sequel-shaped candidate in the same result set by 30+ points, not by a margin that popularity
could close.

Concretely, in `marqueeScoreCandidate()` the per-candidate `marqueeNormaliseTitle($title)` call
becomes `marqueeNormaliseTitleForType($title, $type)`, which requires a `string $type` parameter
on that function. It has no callers outside `marqueeResolveWork()`, so the signature change is
contained. `marqueeResolveWork()` normalises the query the same way, once.

### 3. Cache key derives from the same wrapper

`posters/index.php:63` builds `resolve:<type>:<normalised q>:<year>`. Switching it to
`marqueeNormaliseTitleForType()` makes `Star Wars` and `Star Wars Collection` share one
resolution entry, which is the behaviour the existing "different queries resolve to the same
work" requirement already asks for. Pre-existing entries keyed on the suffixed form simply
become unreachable and expire inside the 24h TTL — a cold miss, never stale data.

### 4. Rejection diagnostics via a by-reference out-parameter

`marqueeResolveWork()` keeps its `?array` return and its "one work or nothing" contract; the
scored list comes back through a new trailing parameter:

```php
function marqueeResolveWork(
    array $searchPayload,
    string $type,
    string $query,
    ?int $year,
    ?array &$diagnostics = null
): ?array
```

populated on every call with:

```php
$diagnostics = [
    'query_normalised' => $normalisedQuery,
    'score_floor' => RESOLVE_SCORE_FLOOR,
    'candidates' => [ ['tmdb_id' =>, 'title' =>, 'year' =>, 'score' =>], … ],  // top-first, capped at 10
];
```

Every existing caller keeps working untouched, including the two fixture assertions that check
`marqueeResolveWork(...) === null`. This is why the out-param wins over the tidier alternative
of always returning an array with `winner => null`: that would rewrite the null check at
`index.php:80`, both fixture assertions, and the meaning of the function's return type, for a
diagnostic that only one caller reads.

`candidates` is capped at 10 like the existing `rejected` list, and is populated from the same
sorted array, so an empty `candidates` unambiguously means the provider returned nothing —
exactly the distinction the handoff asks for.

### 5. Debug on both `no_match` exits

`marqueeSendFailure()` already accepts an `$extra` array merged into the top level, so the
resolution 404 needs only `['debug' => [...]]` passed through, gated on `$request['debug']`.

The season-not-found 404 at `index.php:160` is the other `no_match` exit. It gets a debug block
too — the resolved winner and the accumulated `$debugCalls` are both in scope there, so it is a
few lines, and a `no_match` that sometimes carries `debug` and sometimes does not is the kind of
inconsistency that costs the next debugging session. Its block reports the resolution that
succeeded plus the season calls that failed.

The failure block deliberately does not mimic the success block's shape — there is no winner to
report and no artwork fan-out to list. It carries `resolution` and `calls` only.

### 6. Fixtures in Plex's vocabulary

The suite passes today and nothing here contradicts it; the gap is vocabulary. 71 fixture tests
and 45 live checks missed this because every collection fixture is TMDB-named. New cases:

| Case | Expectation |
| --- | --- |
| `Star Wars` + `collection` | resolves to the `Star Wars Collection` record |
| `Star Wars Collection` + `collection` | still resolves — the suffixed form must not regress |
| `star wars collection` + `collection` | resolves to the same record |
| `The Matrix` + `movie` | resolves to the 1999 film, not `The Matrix Collection` and not `The Matrix Reloaded` |
| `The Collection` + `movie` | resolves to the film; the strip did not fire |
| invented collection title + `collection` | still `null` |
| `Collection` + `collection` | does not normalise to empty and match everything |
| `marqueeNormaliseTitleForType` unit cases | suffix stripped for `collection`, retained for `movie`; mid-string occurrence retained |

The `The Matrix` + `movie` case is the load-bearing one: it is the guard that proves movie
matching did not loosen, and it should assert the resolved `tmdb_id`, not merely that something
resolved.

## Risks / Trade-offs

- **Two TMDB collections collapsing to the same normalised name** → Already handled: the sort
  breaks ties on popularity then `tmdb_id`, both deterministic, so the outcome is stable rather
  than arbitrary. No new ambiguity is introduced that the exact-match path did not already have.
- **A collection legitimately named just `Collection`** → The empty-result guard in decision 1
  returns the unstripped form, so it cannot degenerate into matching every candidate.
- **`marqueeScoreCandidate()` gains a parameter** → Contained: one call site, no callers in the
  test suite. Verified by grep before the change and re-verified after.
- **The debug block on a 404 leaks upstream detail** → It reports only TMDB titles, ids, scores
  and HTTP statuses, all already present in the success-path debug block, and only when
  `debug=true` is explicitly supplied. No credential or URL is included.
- **Trade-off: the fix is vocabulary-specific, not general.** It handles exactly one
  upstream-defined suffix. That is deliberate — `Collection` is a closed vocabulary with a
  deterministic mapping, which is what makes it safe to normalise; arbitrary user annotations
  are not, and generalising here would reintroduce the sequel-matching failure.

## Migration Plan

No schema, no dependency, no environment variable, no build step — PHP served directly from the
filesystem. Deploy is the commit; rollback is reverting it. The resolution cache self-heals
within its 24h TTL and needs no manual clear, since the key changes rather than the value.

Verification order: run `php marquee/api/v1/tests/resolve_test.php` for the whole suite, then
confirm against the live endpoint that `q=Star Wars&type=collection` returns 200 with posters,
that `q=Star Wars Collection` still does, that `q=The Matrix&type=movie` still resolves to the
1999 film, and that `q=Christmas+Movies&type=collection&debug=true` returns a 404 whose debug
block shows an empty candidate list.

## Open Questions

None blocking. One judgement call worth flagging for review rather than resolving here: the
failure debug block's shape differs from the success one (`resolution` + `calls`, with no
`winner` and no `ms`). If a future client wants to parse both uniformly, that shape is the thing
to revisit — but no client parses `debug` today, and `debug` is documented as a diagnostic
rather than part of the response contract.
