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

2. An optional `page` field on poster objects.

   {
     "url":    "https://static.tvmaze.com/uploads/images/original_untouched/35/87912.jpg",
     "thumb":  "https://static.tvmaze.com/uploads/images/medium_portrait/35/87912.jpg",
     "source": "tvmaze",
     "width":  680,
     "height": 1000,
     "page":   "https://www.tvmaze.com/shows/169/breaking-bad"
   }

   `page` is an absolute URL to the supplying source's page for the work. It is
   present only on posters from sources whose licence requires a link back, and is
   omitted entirely otherwise. Today only TVmaze populates it. For a `type=season`
   poster it points at the season's own page, which differs from the show's page.

   TVmaze poster objects carry no `language` and no `score`. A TVmaze season poster
   additionally carries no `width` or `height`. Do not assume any optional field is
   present.

## Rendering `page` is a licence obligation, not a nice-to-have

TVmaze data is licensed CC BY-SA. Attribution is satisfied by linking back to TVmaze
from within the application, and the `page` field exists so that a compliant client
is possible without hardcoded knowledge of any provider.

So: wherever Marquee displays a poster that carries `page`, it must offer a visible,
working link to that URL. A tooltip nobody can click, or a credit buried in an about
screen, does not discharge the obligation for the image on screen.

If we are not prepared to render the link, the correct action is to exclude TVmaze
with `sources=` rather than to use its artwork uncredited.

## Scope of the change

- Point the poster and time calls at /marquee/api/v2/.
- Handle `tvmaze` wherever provider outcomes are displayed, logged, or counted.
- Render `page` as an attribution link on any poster that carries it, generically —
  keyed on the presence of the field, not on `source == "tvmaze"`, so a future source
  with the same obligation works without a client change.
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
- Where does the attribution link belong in the poster UI — on the card, in the
  detail or lightbox view, or both?

Investigate the current API client and poster-rendering code before proposing, and
verify the response shape against the live v2 endpoint rather than trusting this
description.
```
