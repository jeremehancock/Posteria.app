# Addendum: `page` became universal after the first handover

The first handover (`client-handover.md`) described `page` as present only on
TVmaze posters, and told the client to key its attribution obligation on the
presence of that field. Both are now out of date.

After that prompt was written, probing showed all four sources can be linked with no
extra API call and no title-derived slug, so `page` is carried by every source and a
new `attribution_required` marks the licence case. See `design.md`,
"Every source carries a link, and the licence-required ones are marked".

Paste the block below into the Marquee session that already implemented the first
handover. It is written as a delta — it assumes that work exists and describes only
what changed.

---

```
The Posteria Marquee API v2 contract changed after the handover prompt you already
implemented. This is a small follow-up, not a rewrite — the change is additive and
your current code still works and is still licence-compliant. What is now wrong is
the *intent* the UI expresses.

First, check the state of the change you implemented:
  - still active     -> /opsx:update it with the revisions below
  - already archived -> /opsx:propose a small follow-up change

## What you were told

That `page` is present only on posters whose source licence requires a link back,
that today only TVmaze populates it, and that the obligation is therefore keyed on
whether `page` is present.

## What is actually true now

`page` is present on posters from EVERY source:

    tmdb       https://www.themoviedb.org/tv/1396
    fanart.tv  https://fanart.tv/series/81189/
    thetvdb    https://thetvdb.com/series/breaking-bad
    tvmaze     https://www.tvmaze.com/shows/169/breaking-bad

And a new field carries the obligation instead:

    "attribution_required": true

present and true ONLY on posters whose source licence requires the link to be
rendered. Today that is TVmaze alone. Every other source omits it entirely — it is
never present and false.

The two fields now mean different things:

  page                   a link to where this poster came from. Provenance.
                         Rendering it is good product, and our choice.

  attribution_required   the source's licence obliges us to render that link.
                         TVmaze is CC BY-SA. Not our choice.

## Why this needs a change on your side

Your current code most likely does something equivalent to:

    if (poster.page) { renderAttributionLink(poster.page) }

That is still compliant — it over-attributes rather than under-attributes, which is
the safe direction. But it is now wrong in three ways worth fixing:

  1. It fires on every poster instead of a minority, so anything sized or styled for
     sparsity (a small badge, a corner icon, a footnote) now appears on 100% of
     results.
  2. If the link carries attribution-flavoured copy — "Attribution required",
     "CC BY-SA", "Required by licence" — that copy is now displayed on TMDB,
     fanart.tv and TheTVDB posters, where it is simply false.
  3. The obligation is no longer expressed anywhere in your code, so a later change
     that thins out the links has nothing to stop it dropping the one that matters.

## What to change

- Split the concept in two:
    - `page` -> a source link, shown wherever you think it belongs. Your call.
    - `attribution_required` -> the subset that MUST be visibly linked wherever the
      poster itself is shown.
- Key the obligation on `attribution_required` only. Never on `poster.page` and never
  on `source === "tvmaze"` — a future source with the same licence must work with no
  client release.
- Move any attribution-specific wording onto the marked posters only. Unmarked
  posters should read as neutral provenance ("View on TMDB", a source chip, an
  external-link icon), not as a licence notice.
- Treat `page` as optional in code regardless. A source with no resolvable identifier
  omits it rather than guessing, so a poster without `page` is valid.

## Also worth knowing

For `type=season`, sources that publish a season page link to the season
(`/tv/1396/season/2`, `/series/breaking-bad/seasons/official/2`,
`/seasons/754/breaking-bad-season-2`). fanart.tv has no season page and falls back to
the series page. So two posters in the same response can carry different `page` URLs
for the same work, which is correct and not a bug.

Nothing else changed: same paths, same X-Client-Info header, same parameters, same
failure codes, same `providers` vocabulary, same `tvmaze` behaviour on movies and
collections.

Verify the shape against the live v2 endpoint rather than trusting this description.
```
