## Why

Marquee's poster search draws on three sources — TMDB, fanart.tv and TheTVDB — all
of which require a credential this deployment has to hold and keep working. TVmaze
publishes a free, keyless, identifier-addressable JSON API with 14–45 posters per
show and artwork for every season, and it is the first source that can be added
without a new environment variable, a billing relationship, or any relaxation of the
rules that keep this endpoint's provenance honest.

Adding it to the deployed `v1` endpoint would change the `providers` map and the
`sources` vocabulary under clients that are already running. Publishing it as `v2`
alongside a frozen `v1` lets every Marquee install upgrade by changing one path
segment, on its own schedule, with no possibility of a running client breaking.

## What Changes

- **New endpoint tree at `/marquee/api/v2/`**, a copy of `v1` carrying the new
  source. `GET /marquee/api/v2/posters` and `GET /marquee/api/v2/time`.
- **`v1` is frozen.** It keeps serving its current contract unchanged and no file
  under `marquee/api/v1/` is modified. This is the same containment `v1` applies to
  the legacy `api/fetch/posters` endpoint.
- **TVmaze becomes a fourth source**, named `tvmaze` in `providers` and in each
  poster's `source`, and selectable with the `sources=tvmaze` token.
  - Shows: located by TVDB id, falling back to IMDb id; all `poster`-typed images.
  - Seasons: the season's own image, which is frequently artwork no other source has.
  - Movies and collections: TVmaze indexes neither, so it reports `no_data`.
- **Poster objects gain an optional `page` field** — an absolute URL to the source's
  page for the work. TVmaze is CC BY-SA and requires a link back; this is where that
  link lives. Sources that impose no such requirement omit the field.
- **No new environment variable.** TVmaze needs no credential. The four variables
  `v1` reads remain the complete set.
- **No breaking change for any deployed client.** Every change lands on a new path;
  `v1` responses are byte-for-byte what they are today.

## Capabilities

### New Capabilities

None. `v2` is the same three capabilities served at a new path with a fourth source.
Introducing parallel `marquee-v2-*` specs would duplicate ~700 lines of requirements
that are identical between the versions and would leave two documents to keep in step.

### Modified Capabilities

- `marquee-poster-sources`: TVmaze added to the supported set, the `sources`
  vocabulary and identifier resolution; a source may now be credential-free; a source
  that structurally cannot hold a media type reports `no_data`; attribution
  obligations are stated; `v1` is declared frozen.
- `marquee-poster-search`: the endpoint moves to `/marquee/api/v2/posters`; poster
  objects gain the optional `page` field.
- `marquee-api-access`: the time endpoint moves to `/marquee/api/v2/time`.

## Impact

- **New code**: `marquee/api/v2/` — a copy of the `v1` tree plus `lib/tvmaze.php`.
- **Untouched**: everything under `marquee/api/v1/` and everything under `api/`.
- **Deployment**: none required. `nixpacks.toml` runs `php -S -t .` from the repo
  root, so the new directory is routable the moment it exists. No new secret to set.
- **Upstream dependency**: `api.tvmaze.com`, unauthenticated, rate limited to roughly
  20 calls per 10 seconds per server IP address, licensed CC BY-SA.
- **Downstream**: the Marquee client needs a separate change to point at `v2`, offer
  the `tvmaze` token, and render the `page` link. That work is out of scope here and
  is handed over as its own proposal.
