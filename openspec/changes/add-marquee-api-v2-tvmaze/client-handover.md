# Handover: updating the Marquee app to consume the v2 API

This is the prompt to paste into a session working in the **Marquee app repository**
(not this one). It is written to stand alone — that session has no context from the
work that produced the `v2` endpoint.

Referenced by `tasks.md` 6.5. Give it to that session only after `v2` is deployed and
answering, so the client change can be verified against a live endpoint.

---

```
/opsx:propose Update Marquee to consume the Posteria Marquee API v2, which adds TVmaze as a fourth poster source.

## Context

Marquee currently calls the Posteria poster API at:

    https://posteria.app/marquee/api/v1/posters
    https://posteria.app/marquee/api/v1/time

A v2 endpoint now exists at the same host under /marquee/api/v2/. v1 is frozen, not
deprecated — it keeps serving its current contract indefinitely, so this upgrade is
on our schedule and carries no deadline.

v2 is v1 plus one new source. The request contract is unchanged: same X-Client-Info
header, same parameters, same failure codes, same status codes. Upgrading is a path
change plus handling of two additive response fields.

## What is different in v2

1. A fourth source, `tvmaze`.

   - Appears as a key in the `providers` map on every response.
   - Appears as the `source` on posters it supplied.
   - Selectable with the `sources=tvmaze` request token, alongside the existing
     `tmdb`, `fanart` and `tvdb`.
   - Television only. For `type=movie` and `type=collection` it always reports
     `no_data`. That is expected and is NOT a provider failure — those responses are
     not marked `partial` and must not surface as an error or a warning in the UI.
   - For `type=show` it typically supplies 14-45 posters. For `type=season` it
     supplies at most one image, but that image is frequently artwork no other
     source carries, which is the main reason it is worth having.

2. A `page` field on poster objects, from every source.

   {
     "url":    "https://static.tvmaze.com/uploads/images/original_untouched/35/87912.jpg",
     "thumb":  "https://static.tvmaze.com/uploads/images/medium_portrait/35/87912.jpg",
     "source": "tvmaze",
     "width":  680,
     "height": 1000,
     "page":   "https://www.tvmaze.com/shows/169/breaking-bad",
     "attribution_required": true
   }

   `page` is an absolute URL to the supplying source's page for the work — where a
   user can see that artwork in its original context. Every source carries one:

     tmdb       https://www.themoviedb.org/tv/1396
     fanart.tv  https://fanart.tv/series/81189/
     thetvdb    https://thetvdb.com/series/breaking-bad
     tvmaze     https://www.tvmaze.com/shows/169/breaking-bad

   For `type=season`, sources that publish a season page link to the season
   (`/tv/1396/season/2`, `/series/breaking-bad/seasons/official/2`,
   `/seasons/754/breaking-bad-season-2`); fanart.tv has no season page and falls back
   to the series page. Treat `page` as optional in code anyway — a source with no
   resolvable identifier omits it rather than guessing.

3. An `attribution_required` field, present and `true` only where the source's
   licence requires the link to be rendered. Today that is TVmaze alone. Every other
   source omits it entirely — it is never present and `false`.

   TVmaze poster objects carry no `language` and no `score`. A TVmaze season poster
   additionally carries no `width` or `height`. Do not assume any optional field is
   present.

## `page` is provenance; `attribution_required` is an obligation

The two are deliberately separate, and the distinction matters:

- **`page`** is a courtesy link on most sources. Rendering it is good product — it
  lets a user click through to where the poster came from — but it is our choice.
- **`attribution_required: true`** means the source's licence obliges us to render
  the link. TVmaze data is CC BY-SA, and attribution is satisfied by linking back to
  TVmaze from within the application.

So Marquee may style, collapse, or place `page` links however it likes — **except**
that wherever it displays a poster carrying `attribution_required`, it must offer a
visible, working link to that poster's `page`. A tooltip nobody can click, or a
credit buried in an about screen, does not discharge the obligation for the image on
screen.

Key on the presence of `attribution_required`, never on `source == "tvmaze"`. That is
the whole point of the field: a future source with the same obligation must work with
no client change.

If we are not prepared to render the marked links, the correct action is to exclude
TVmaze with `sources=` rather than to use its artwork uncredited.

## Scope of the change

- Point the poster and time calls at /marquee/api/v2/.
- Handle `tvmaze` wherever provider outcomes are displayed, logged, or counted.
- Render `page` as a source link wherever a poster is shown. Mandatory for posters
  carrying `attribution_required`; our choice, but recommended, for the rest.
- Key the obligation on `attribution_required`, never on the source name.
- Add `tvmaze` to the source-selection UI if one exists.
- Treat `tvmaze: no_data` on a movie or collection search as normal and silent.
- Keep tolerating unknown keys in `providers` and unknown fields on posters, so a
  future v3 source does not require a client release.

## Explicitly out of scope

- Any change to how Marquee authenticates. The X-Client-Info header is unchanged.
- Any new user-facing setting, credential, or API key. v2 introduces none.
- Filtering or sorting posters by source. v2 already ranks them.

## Questions worth settling in the proposal

- Do we cut over to v2 outright, or fall back to v1 if v2 returns a transport error?
  A fallback is cheap because the contracts are compatible, but it means two paths to
  test and it silently loses TVmaze artwork when it triggers.
- Is the API base path currently a constant, a setting, or hardcoded? If a setting,
  existing installs will carry a stored v1 path that an upgrade has to migrate.
- Where does the source link belong in the poster UI — on the card, in the detail or
  lightbox view, or both? Note the answer can differ for `attribution_required`
  posters, which must be linked wherever they are shown, and the rest, which are
  discretionary.

Investigate the current API client and poster-rendering code before proposing, and
verify the response shape against the live v2 endpoint rather than trusting this
description.
```
