#!/usr/bin/env bash
# Live verification for /marquee/api/v2/posters.
#
# Runs the acceptance checks that need real provider credentials. Reads
# TMDB_API_KEY, FANART_API_KEY and TVDB_API_KEY from the environment; any that is
# unset simply shows up as a `skipped` source, which is itself one of the checks.
#
#   TMDB_API_KEY=... FANART_API_KEY=... TVDB_API_KEY=... \
#     bash marquee/api/v2/tests/verify_live.sh
#
# Starts its own PHP server on port 8799 against the repo root and stops it on
# exit. Does not touch the deployment.
#
# TVmaze needs no credential, so its checks run on any deployment. Note that its
# rate limit is per server IP rather than per key (~20 calls / 10 s), which is why
# this suite reuses cached responses where it can.

set -uo pipefail

PORT=${PORT:-8799}
ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)
BASE="http://127.0.0.1:$PORT/marquee/api/v2/posters"
WORK=$(mktemp -d)
PASS=0
FAIL=0

cleanup() {
  [[ -n ${SRV_PID:-} ]] && kill "$SRV_PID" 2>/dev/null
  rm -rf "$WORK"
}
trap cleanup EXIT

hdr() {
  printf '{"name":"Marquee","version":"verify","ts":%s}' "$(date +%s%3N)" | base64 -w0
}

# get <file> <query...>  -> writes body to $WORK/<file>, echoes status
get() {
  local out=$1; shift
  curl -s -o "$WORK/$out" -w '%{http_code}' -H "X-Client-Info: $(hdr)" "$BASE?$*"
}

check() { # check <label> <condition-result> [detail]
  local label=$1 ok=$2 detail=${3:-}
  if [[ $ok == "1" ]]; then
    PASS=$((PASS + 1)); printf '  \033[32mPASS\033[0m  %-52s %s\n' "$label" "$detail"
  else
    FAIL=$((FAIL + 1)); printf '  \033[31mFAIL\033[0m  %-52s %s\n' "$label" "$detail"
  fi
}

jq_py() { # jq_py <file> <python expression over `d`>
  python3 -c "
import json,sys
d=json.load(open('$WORK/$1'))
print($2)
" 2>/dev/null || echo "ERR"
}

echo "Starting server on :$PORT (docroot $ROOT)"
php -S "127.0.0.1:$PORT" -t "$ROOT" >"$WORK/server.log" 2>&1 &
SRV_PID=$!
sleep 2

echo
echo "=== 6.1 The Matrix, movie, year 1999 ==="
S=$(get matrix.json "q=The+Matrix&type=movie&year=1999")
check "status 200" "$([[ $S == 200 ]] && echo 1 || echo 0)" "got $S"
check "resolves to TMDB 603" "$([[ $(jq_py matrix.json "d['match']['tmdb_id']") == 603 ]] && echo 1 || echo 0)" \
  "$(jq_py matrix.json "repr((d['match']['title'], d['match']['year']))")"
SIZE=$(stat -c%s "$WORK/matrix.json")
check "payload under 100 KB" "$([[ $SIZE -lt 102400 ]] && echo 1 || echo 0)" "$((SIZE / 1024)) KB"
check "no duplicate urls" "$(jq_py matrix.json "int(len({p['url'] for p in d['posters']})==len(d['posters']))")" \
  "total=$(jq_py matrix.json "d['total']") returned=$(jq_py matrix.json "len(d['posters'])")"
check "every poster has a url" "$(jq_py matrix.json "int(all(p.get('url') for p in d['posters']))")"
check "thumb present on tmdb posters" \
  "$(jq_py matrix.json "int(all('thumb' in p for p in d['posters'] if p['source']=='tmdb'))")"
echo "  providers: $(jq_py matrix.json "d['providers']")"
echo "  sources:   $(jq_py matrix.json "{p['source'] for p in d['posters']}")"

echo
echo "=== 6.2 The Matrix, movie, no year ==="
S=$(get matrix_noyear.json "q=The+Matrix&type=movie")
check "still resolves to 603" "$([[ $(jq_py matrix_noyear.json "d['match']['tmdb_id']") == 603 ]] && echo 1 || echo 0)" \
  "$(jq_py matrix_noyear.json "repr(d['match']['title'])")"

echo
echo "=== 6.3 Shows ==="
get bb_show.json "q=Breaking+Bad&type=show" >/dev/null
check "Breaking Bad resolves to 1396" "$([[ $(jq_py bb_show.json "d['match']['tmdb_id']") == 1396 ]] && echo 1 || echo 0)" \
  "$(jq_py bb_show.json "repr(d['match']['title'])")"
get st.json "q=Stranger+Things&type=show" >/dev/null
check "Stranger Things resolves to 66732" "$([[ $(jq_py st.json "d['match']['tmdb_id']") == 66732 ]] && echo 1 || echo 0)" \
  "$(jq_py st.json "repr(d['match']['title'])")"
check "show has a tvdb_id (fanart TV depends on it)" \
  "$(jq_py bb_show.json "int(d['match']['tvdb_id'] is not None)")" "tvdb_id=$(jq_py bb_show.json "d['match']['tvdb_id']")"
check "fanart TV posters returned for show (4.7)" \
  "$(jq_py bb_show.json "int(any(p['source']=='fanart.tv' for p in d['posters']))")" \
  "fanart=$(jq_py bb_show.json "sum(1 for p in d['posters'] if p['source']=='fanart.tv')")"

echo
echo "=== 6.4 Seasons ==="
get bb_s2.json "q=Breaking+Bad&type=season&season=2" >/dev/null
check "season 2 resolves" "$([[ $(jq_py bb_s2.json "d['match']['season']['number']") == 2 ]] && echo 1 || echo 0)" \
  "$(jq_py bb_s2.json "repr(d['match']['season'])")"
check "season 2 returns art" "$(jq_py bb_s2.json "int(d['total']>0)")" "total=$(jq_py bb_s2.json "d['total']")"
# match.tmdb_id on a season is the SHOW's id, never a season-level id. Clients
# store this value and send it back with season=N, so a regression here is
# invisible on the client — both are positive integers — and would silently
# poison every stored season mapping.
check "season match.tmdb_id is the show's id" \
  "$([[ $(jq_py bb_s2.json "d['match']['tmdb_id']") == 1396 ]] && echo 1 || echo 0)" \
  "match.tmdb_id=$(jq_py bb_s2.json "d['match']['tmdb_id']") (show=1396)"
check "season match.tmdb_id equals the show request's id" \
  "$([[ $(jq_py bb_s2.json "d['match']['tmdb_id']") == $(jq_py bb_show.json "d['match']['tmdb_id']") ]] && echo 1 || echo 0)" \
  "season=$(jq_py bb_s2.json "d['match']['tmdb_id']") show=$(jq_py bb_show.json "d['match']['tmdb_id']")"
check "no duplicate urls" "$(jq_py bb_s2.json "int(len({p['url'] for p in d['posters']})==len(d['posters']))")"
# A season request must never fetch show-level artwork. Asserted structurally
# from the debug call list rather than by comparing URL sets: TMDB files some
# images under both a show and one of its seasons, so an identical URL appearing
# in both responses is the provider's own classification, not a leak here.
rm -rf "${TMPDIR:-/tmp}/marquee-api-v2"   # force a cold fetch so calls are recorded
get bb_s2_dbg.json "q=Breaking+Bad&type=season&season=2&debug=true" >/dev/null
check "season request makes no show-images call" \
  "$(jq_py bb_s2_dbg.json "int(not any(c.get('call')=='images' for c in d['debug']['calls']))")" \
  "calls=$(jq_py bb_s2_dbg.json "[c.get('call') for c in d['debug']['calls'] if c.get('call')]")"
check "season art is a small set, not the show's catalogue" \
  "$(python3 -c "
import json
s=json.load(open('$WORK/bb_s2.json'))['total']
h=json.load(open('$WORK/bb_show.json'))['total']
print(int(s < h/2))" 2>/dev/null || echo 0)" \
  "season=$(jq_py bb_s2.json "d['total']") show=$(jq_py bb_show.json "d['total']")"
S=$(get bb_s0.json "q=Breaking+Bad&type=season&season=0")
check "season 0 (Specials) supported" "$([[ $S == 200 ]] && echo 1 || echo 0)" \
  "status=$S total=$(jq_py bb_s0.json "d.get('total')")"
check "Specials named correctly" \
  "$([[ $(jq_py bb_s0.json "d['match']['season']['number']") == 0 ]] && echo 1 || echo 0)" \
  "$(jq_py bb_s0.json "repr(d['match']['season'].get('name'))")"
check "fanart season art returned (4.7)" \
  "$(jq_py bb_s2.json "int(any(p['source']=='fanart.tv' for p in d['posters']))")" \
  "fanart=$(jq_py bb_s2.json "sum(1 for p in d['posters'] if p['source']=='fanart.tv')")"

echo
echo "=== 6.5 Collections ==="
get sw.json "q=Star+Wars+Collection&type=collection" >/dev/null
check "resolves to TMDB collection 10" "$([[ $(jq_py sw.json "d['match']['tmdb_id']") == 10 ]] && echo 1 || echo 0)" \
  "$(jq_py sw.json "repr(d['match']['title'])")"
check "no duplicate urls" "$(jq_py sw.json "int(len({p['url'] for p in d['posters']})==len(d['posters']))")"

# A media server names this collection "Star Wars"; TMDB names it "Star Wars
# Collection". The checks above probed only the provider's vocabulary, which is
# how a total failure of the client's vocabulary shipped unnoticed. These probe
# what the client actually sends.
get sw_bare.json "q=Star+Wars&type=collection" >/dev/null
check "unsuffixed 'Star Wars' resolves to collection 10" \
  "$([[ $(jq_py sw_bare.json "d['match']['tmdb_id']") == 10 ]] && echo 1 || echo 0)" \
  "$(jq_py sw_bare.json "repr(d.get('match',{}).get('title'))")"
check "unsuffixed 'Star Wars' returns art" \
  "$(jq_py sw_bare.json "int(d.get('total',0)>0)")" "total=$(jq_py sw_bare.json "d.get('total')")"

# Both vocabularies must land on the one record.
check "suffixed and unsuffixed agree on the record" \
  "$([[ $(jq_py sw.json "d['match']['tmdb_id']") == $(jq_py sw_bare.json "d['match']['tmdb_id']") ]] && echo 1 || echo 0)" \
  "suffixed=$(jq_py sw.json "d['match']['tmdb_id']") bare=$(jq_py sw_bare.json "d['match']['tmdb_id']")"

# Asserted on the resolved title rather than a hardcoded id: these ids are not
# established anywhere else in this suite, and the defect shows up as a 404 in
# any case, which a title check catches just as well.
for Q in "Alien" "Harry+Potter"; do
  F="coll_$(echo "$Q" | tr -cd '[:alnum:]').json"
  S=$(get "$F" "q=$Q&type=collection")
  check "unsuffixed '$Q' resolves" "$([[ $S == 200 ]] && echo 1 || echo 0)" \
    "status=$S $(jq_py "$F" "repr(d.get('match',{}).get('title'))")"
  check "unsuffixed '$Q' resolved to a matching title" \
    "$(jq_py "$F" "int('$(echo "$Q" | tr '+' ' ')'.lower() in d['match']['title'].lower())")" \
    "$(jq_py "$F" "repr(d.get('match',{}).get('title'))")"
  check "unsuffixed '$Q' returns art" \
    "$(jq_py "$F" "int(d.get('total',0)>0)")" "total=$(jq_py "$F" "d.get('total')")"
done

echo
echo "=== 6.6 No match ==="
S=$(get nomatch.json "q=Zzzznotarealtitle&type=movie")
check "404 for an unknown title" "$([[ $S == 404 ]] && echo 1 || echo 0)" "status=$S"
check "code is no_match" "$([[ $(jq_py nomatch.json "d['code']") == no_match ]] && echo 1 || echo 0)" \
  "$(jq_py nomatch.json "repr(d.get('code'))")"

# A custom collection has no upstream record. Still a 404, and the debug block on
# the failure is what distinguishes that from a below-floor rejection.
S=$(get custom_coll.json "q=Christmas+Movies&type=collection&debug=true")
check "custom collection still 404s" "$([[ $S == 404 ]] && echo 1 || echo 0)" "status=$S"
check "404 carries a debug block" "$(jq_py custom_coll.json "int('debug' in d)")"
check "404 debug reports the floor" \
  "$(jq_py custom_coll.json "int(d['debug']['resolution']['score_floor']>0)")" \
  "floor=$(jq_py custom_coll.json "d['debug']['resolution'].get('score_floor')")"
check "404 debug reports the normalised query" \
  "$(jq_py custom_coll.json "int(d['debug']['resolution']['query_normalised']=='christmas movies')")" \
  "$(jq_py custom_coll.json "repr(d['debug']['resolution'].get('query_normalised'))")"
check "404 debug lists what was scored" \
  "$(jq_py custom_coll.json "int(isinstance(d['debug']['resolution']['candidates'], list))")" \
  "candidates=$(jq_py custom_coll.json "len(d['debug']['resolution']['candidates'])")"
check "404 keeps its code alongside debug" \
  "$([[ $(jq_py custom_coll.json "d['code']") == no_match ]] && echo 1 || echo 0)"
check "no debug key on a 404 when not requested" \
  "$(jq_py nomatch.json "int('debug' not in d)")"

echo
echo "=== 6.9 Identification by tmdb_id ==="
# A supplied id skips title resolution entirely. The deliberately wrong title
# proves it: if the title were consulted at all, this would not return 603.
get byid.json "q=Totally+Wrong+Title&type=movie&tmdb_id=603&debug=true" >/dev/null
check "id resolves regardless of the title" \
  "$([[ $(jq_py byid.json "d['match']['tmdb_id']") == 603 ]] && echo 1 || echo 0)" \
  "$(jq_py byid.json "repr(d.get('match',{}).get('title'))")"
check "id path returns artwork" "$(jq_py byid.json "int(d['total']>0)")" "total=$(jq_py byid.json "d['total']")"
check "match title comes from the provider, not the client" \
  "$([[ $(jq_py byid.json "repr(d['match']['title'])") == "'The Matrix'" ]] && echo 1 || echo 0)" \
  "$(jq_py byid.json "repr(d['match']['title'])")"
check "query echoes the supplied id" \
  "$([[ $(jq_py byid.json "d['query']['tmdb_id']") == 603 ]] && echo 1 || echo 0)"
check "debug reports identification by id" \
  "$([[ $(jq_py byid.json "d['debug']['identified_by']") == tmdb_id ]] && echo 1 || echo 0)" \
  "$(jq_py byid.json "repr(d['debug'].get('identified_by'))")"
check "id path makes no search call" \
  "$(jq_py byid.json "int(not any(c.get('call')=='search' for c in d['debug']['calls']))")" \
  "calls=$(jq_py byid.json "[c.get('call') for c in d['debug']['calls'] if c.get('call')]")"

# Season: the id is the SHOW's, paired with a season number.
get byid_season.json "q=Breaking+Bad&type=season&season=2&tmdb_id=1396" >/dev/null
check "season by show id returns season 2" \
  "$([[ $(jq_py byid_season.json "d['match']['season']['number']") == 2 ]] && echo 1 || echo 0)" \
  "$(jq_py byid_season.json "repr(d.get('match',{}).get('season'))")"
check "season by show id returns art" \
  "$(jq_py byid_season.json "int(d['total']>0)")" "total=$(jq_py byid_season.json "d['total']")"
check "season by id echoes the show id back as match.tmdb_id" \
  "$([[ $(jq_py byid_season.json "d['match']['tmdb_id']") == 1396 ]] && echo 1 || echo 0)" \
  "match.tmdb_id=$(jq_py byid_season.json "d['match']['tmdb_id']")"
check "season id round-trips unchanged" \
  "$(jq_py byid_season.json "int(d['query']['tmdb_id'] == d['match']['tmdb_id'])")" \
  "query=$(jq_py byid_season.json "d['query']['tmdb_id']") match=$(jq_py byid_season.json "d['match']['tmdb_id']")"

# --- query echo -------------------------------------------------------------
# `query` belongs to the caller, not to the resolved work, but the artwork cache
# is keyed on the work. A cached payload must not carry the `query` block of
# whichever request happened to populate the entry: that reports another caller's
# search text and another caller's tmdb_id, which silently breaks stale-id
# detection and leaks one caller's query to the next.
get echo_seed.json "q=ZZ_ECHO_SEED&type=movie&tmdb_id=603&limit=1&sources=tmdb" >/dev/null
check "seeding request echoes its own q" \
  "$([[ $(jq_py echo_seed.json "repr(d['query']['q'])") == "'ZZ_ECHO_SEED'" ]] && echo 1 || echo 0)" \
  "$(jq_py echo_seed.json "repr(d['query']['q'])")"
check "seeding request echoes its own tmdb_id" \
  "$([[ $(jq_py echo_seed.json "d['query']['tmdb_id']") == 603 ]] && echo 1 || echo 0)"

# Same cache key (same work, same sources, same limit), different caller.
get echo_hit.json "q=The+Matrix&type=movie&limit=1&sources=tmdb" >/dev/null
check "cache hit echoes THIS caller's q" \
  "$([[ $(jq_py echo_hit.json "repr(d['query']['q'])") == "'The Matrix'" ]] && echo 1 || echo 0)" \
  "$(jq_py echo_hit.json "repr(d['query']['q'])")"
check "cache hit reports tmdb_id null when none was sent" \
  "$(jq_py echo_hit.json "int(d['query']['tmdb_id'] is None)")" \
  "$(jq_py echo_hit.json "repr(d['query']['tmdb_id'])")"
check "cache hit still serves the cached work" \
  "$([[ $(jq_py echo_hit.json "d['match']['tmdb_id']") == 603 ]] && echo 1 || echo 0)"
check "query keeps its position in the response" \
  "$(jq_py echo_hit.json "int(list(d.keys())[:2]==['success','query'])")" \
  "keys=$(jq_py echo_hit.json "list(d.keys())")"

# A stale id falls back to the title, and the fallback is visible without debug.
S=$(get stale.json "q=Breaking+Bad&type=show&tmdb_id=99999999&debug=true")
check "stale id falls back to the title" \
  "$([[ $S == 200 && $(jq_py stale.json "d['match']['tmdb_id']") == 1396 ]] && echo 1 || echo 0)" \
  "status=$S match=$(jq_py stale.json "d.get('match',{}).get('tmdb_id')")"
check "fallback is detectable without debug" \
  "$(jq_py stale.json "int(d['query']['tmdb_id'] != d['match']['tmdb_id'])")" \
  "query=$(jq_py stale.json "d['query']['tmdb_id']") match=$(jq_py stale.json "d['match']['tmdb_id']")"
check "debug reports the fallback" \
  "$([[ $(jq_py stale.json "d['debug']['identified_by']") == tmdb_id_unknown_then_title ]] && echo 1 || echo 0)" \
  "$(jq_py stale.json "repr(d['debug'].get('identified_by'))")"

# A stale id whose title also matches nothing is still a plain 404.
S=$(get stale_nomatch.json "q=Zzzznotarealtitle&type=movie&tmdb_id=99999999")
check "stale id + unmatchable title is a 404" "$([[ $S == 404 ]] && echo 1 || echo 0)" "status=$S"
check "and the code is no_match" \
  "$([[ $(jq_py stale_nomatch.json "d['code']") == no_match ]] && echo 1 || echo 0)"

# The case this change exists for: a locally annotated title that cannot resolve
# on its own, rescued by the id.
S=$(get annotated.json "q=Spider-Noir+B%26W&type=show")
check "annotated title alone still 404s" "$([[ $S == 404 ]] && echo 1 || echo 0)" "status=$S"
SNID=$(get spidernoir.json "q=Spider-Noir&type=show" >/dev/null; jq_py spidernoir.json "d['match']['tmdb_id']")
S=$(get annotated_id.json "q=Spider-Noir+B%26W&type=show&tmdb_id=$SNID")
check "annotated title + id returns artwork" \
  "$([[ $S == 200 && $(jq_py annotated_id.json "d['total']") -gt 0 ]] && echo 1 || echo 0)" \
  "id=$SNID status=$S total=$(jq_py annotated_id.json "d.get('total')")"

# Requests without the parameter are unchanged, and echo null.
check "query.tmdb_id is null when not supplied" \
  "$(jq_py matrix.json "int(d['query']['tmdb_id'] is None)")" \
  "$(jq_py matrix.json "repr(d['query'].get('tmdb_id'))")"

# Validation.
for BAD in abc 0 -1 "1.5"; do
  S=$(get "badid.json" "q=The+Matrix&type=movie&tmdb_id=$BAD")
  check "tmdb_id=$BAD is rejected" "$([[ $S == 400 ]] && echo 1 || echo 0)" "status=$S"
done

echo
echo "=== 6.7 URLs resolve ==="
python3 - "$WORK" <<'PY' > "$WORK/urls.txt"
import json,sys
d=json.load(open(f'{sys.argv[1]}/matrix.json'))
seen=set(); out=[]
for p in d['posters']:
    if p['source'] not in seen:
        seen.add(p['source']); out.append(p['url'])
    if 'thumb' in p and ('thumb',) not in seen:
        seen.add(('thumb',)); out.append(p['thumb'])
print('\n'.join(out))
PY
while read -r u; do
  [[ -z $u ]] && continue
  C=$(curl -s -o /dev/null -w '%{http_code}' -I --max-time 15 "$u")
  check "image resolves" "$([[ $C == 200 ]] && echo 1 || echo 0)" "$C  ${u:0:70}"
done < "$WORK/urls.txt"

echo
echo "=== TheTVDB provenance ==="
check "thetvdb posters returned for the movie" \
  "$(jq_py matrix.json "int(any(p['source']=='thetvdb' for p in d['posters']))")" \
  "thetvdb=$(jq_py matrix.json "sum(1 for p in d['posters'] if p['source']=='thetvdb')")"
# Every image must trace to TheTVDB record 169, which is The Matrix. The legacy
# scraper reached the same 20 images by title slug and also stapled them onto two
# other works; locating the record by IMDb id makes that impossible.
check "all thetvdb movie art is from record 169" \
  "$(jq_py matrix.json "int(all('/169/' in p['url'] for p in d['posters'] if p['source']=='thetvdb'))")"
check "thetvdb carries no score (scale is incompatible)" \
  "$(jq_py matrix.json "int(not any('score' in p for p in d['posters'] if p['source']=='thetvdb'))")"
check "thetvdb languages are ISO 639-1" \
  "$(jq_py matrix.json "int(all(len(p.get('language','en'))==2 for p in d['posters'] if p['source']=='thetvdb'))")" \
  "$(jq_py matrix.json "sorted({p.get('language') for p in d['posters'] if p['source']=='thetvdb'})")"
check "thetvdb show art returned" \
  "$(jq_py bb_show.json "int(any(p['source']=='thetvdb' for p in d['posters']))")" \
  "thetvdb=$(jq_py bb_show.json "sum(1 for p in d['posters'] if p['source']=='thetvdb')")"
check "thetvdb season art returned" \
  "$(jq_py bb_s2.json "int(any(p['source']=='thetvdb' for p in d['posters']))")" \
  "thetvdb=$(jq_py bb_s2.json "sum(1 for p in d['posters'] if p['source']=='thetvdb')")"
# Season art must belong to season 2 only: either the legacy 81189-2-* filename
# form or the season-record form for id 40719 (which is Breaking Bad season 2).
check "thetvdb season art is season 2 only" \
  "$(jq_py bb_s2.json "int(all('81189-2' in p['url'] or '40719' in p['url'] for p in d['posters'] if p['source']=='thetvdb'))")"
check "thetvdb specials art returned" \
  "$(jq_py bb_s0.json "int(any(p['source']=='thetvdb' for p in d['posters']))")" \
  "thetvdb=$(jq_py bb_s0.json "sum(1 for p in d['posters'] if p['source']=='thetvdb')")"
check "collections report thetvdb as no_data" \
  "$(jq_py sw.json "int(d['providers'].get('thetvdb')=='no_data')")" \
  "$(jq_py sw.json "d['providers']")"

echo
echo "=== 6.8 / 6.14 provider outcomes ==="
get srcsub.json "q=The+Matrix&type=movie&year=1999&sources=tmdb" >/dev/null
check "unselected sources report skipped" \
  "$(jq_py srcsub.json "int(all(v=='skipped' for k,v in d['providers'].items() if k!='tmdb'))")" \
  "$(jq_py srcsub.json "d['providers']")"
check "only tmdb art when sources=tmdb" \
  "$(jq_py srcsub.json "int({p['source'] for p in d['posters']} <= {'tmdb'})")"

# A rejected credential must degrade to `skipped`, not `error` — otherwise every
# response is `partial` and nothing is ever cached. The `partial` and `all_failed`
# classifications themselves are covered by resolve_test.php, which can exercise
# them directly instead of trying to induce a real provider outage.
echo "  -- rejected credential (bogus FANART_API_KEY) --"
kill $SRV_PID 2>/dev/null; sleep 1
rm -rf "${TMPDIR:-/tmp}/marquee-api-v2"   # a cached response would mask the bad key
FANART_API_KEY=bogus-key-for-failure-test php -S "127.0.0.1:$PORT" -t "$ROOT" >"$WORK/server2.log" 2>&1 &
SRV_PID=$!
sleep 2
S=$(get rejected.json "q=Breaking+Bad&type=show")
check "still returns 200" "$([[ $S == 200 ]] && echo 1 || echo 0)" "status=$S"
check "rejected credential reported skipped" \
  "$(jq_py rejected.json "int(d['providers'].get('fanart.tv')=='skipped')")" \
  "$(jq_py rejected.json "d['providers']")"
check "response is not marked partial" "$(jq_py rejected.json "int(d.get('code') is None)")" \
  "$(jq_py rejected.json "repr(d.get('code'))")"
check "no fanart art when its key is rejected" \
  "$(jq_py rejected.json "int(not any(p['source']=='fanart.tv' for p in d['posters']))")"
check "response stayed cacheable" \
  "$(get rejected2.json "q=Breaking+Bad&type=show&debug=true" >/dev/null; jq_py rejected2.json "int(d['debug']['cache']=='hit')")" \
  "second request cache=$(jq_py rejected2.json "d['debug']['cache']")"

echo "  -- total upstream failure (bogus TMDB_API_KEY) --"
kill $SRV_PID 2>/dev/null; sleep 1
# The resolution cache would otherwise satisfy the search from a previous run and
# the search failure under test would never happen.
rm -rf "${TMPDIR:-/tmp}/marquee-api-v2"
TMDB_API_KEY=bogus-key-for-failure-test php -S "127.0.0.1:$PORT" -t "$ROOT" >"$WORK/server3.log" 2>&1 &
SRV_PID=$!
sleep 2
S=$(get down.json "q=The+Matrix&type=movie")
check "503 when the search provider fails" "$([[ $S == 503 ]] && echo 1 || echo 0)" "status=$S"
check "code is upstream_unavailable" \
  "$([[ $(jq_py down.json "d['code']") == upstream_unavailable ]] && echo 1 || echo 0)" \
  "$(jq_py down.json "repr(d.get('code'))")"

echo
echo "=== TVmaze (v2) ==="
# Restart on a clean environment: the two preceding blocks deliberately ran with
# bogus keys, and these checks need the real ones back.
kill $SRV_PID 2>/dev/null; sleep 1
rm -rf "${TMPDIR:-/tmp}/marquee-api-v2"
php -S "127.0.0.1:$PORT" -t "$ROOT" >"$WORK/server4.log" 2>&1 &
SRV_PID=$!
sleep 2

get tvm_show.json "q=Breaking+Bad&type=show&debug=true" >/dev/null
check "tvmaze reports ok for a show" \
  "$(jq_py tvm_show.json "int(d['providers'].get('tvmaze')=='ok')")" \
  "$(jq_py tvm_show.json "d['providers']")"
check "tvmaze posters returned" \
  "$(jq_py tvm_show.json "int(any(p['source']=='tvmaze' for p in d['posters']))")" \
  "tvmaze=$(jq_py tvm_show.json "sum(1 for p in d['posters'] if p['source']=='tvmaze')")"
# CC BY-SA: every TVmaze poster must carry a link back, or a compliant client is
# impossible. This is a licence term, not a presentation preference.
check "every tvmaze poster carries page" \
  "$(jq_py tvm_show.json "int(all('page' in p for p in d['posters'] if p['source']=='tvmaze'))")" \
  "without page=$(jq_py tvm_show.json "sum(1 for p in d['posters'] if p['source']=='tvmaze' and 'page' not in p)")"
check "tvmaze page points at tvmaze.com" \
  "$(jq_py tvm_show.json "int(all('tvmaze.com' in p['page'] for p in d['posters'] if p['source']=='tvmaze'))")"
# `page` is universal — every source carries one. What is unique to TVmaze is the
# licence obligation, which rides on `attribution_required`, not on the link.
check "tvmaze is the only marked source" \
  "$(jq_py tvm_show.json "int({p['source'] for p in d['posters'] if p.get('attribution_required')} == {'tvmaze'})")" \
  "marked=$(jq_py tvm_show.json "sorted({p['source'] for p in d['posters'] if p.get('attribution_required')})")"
# main is a designation, not a rating; carrying it as a score would put every
# tvmaze poster above every rated one. Same reasoning as fanart's like count.
check "tvmaze carries no score or language" \
  "$(jq_py tvm_show.json "int(not any('score' in p or 'language' in p for p in d['posters'] if p['source']=='tvmaze'))")"
check "tvmaze posters carry dimensions" \
  "$(jq_py tvm_show.json "int(all('width' in p and 'height' in p for p in d['posters'] if p['source']=='tvmaze'))")"
check "tvmaze located by identifier, not title" \
  "$(jq_py tvm_show.json "int(all('lookup/shows?' in c.get('url','') or 'shows/' in c.get('url','') for c in d['debug']['calls'] if c['source']=='tvmaze'))")" \
  "$(jq_py tvm_show.json "[c.get('call') for c in d['debug']['calls'] if c['source']=='tvmaze']")"
check "no tvmaze search call was made" \
  "$(jq_py tvm_show.json "int(not any('search/shows' in c.get('url','') for c in d['debug']['calls']))")"

# Season artwork links to the season's own page, which differs from the show's.
get tvm_s2.json "q=Breaking+Bad&type=season&season=2" >/dev/null
check "tvmaze season poster returned" \
  "$(jq_py tvm_s2.json "int(any(p['source']=='tvmaze' for p in d['posters']))")" \
  "tvmaze=$(jq_py tvm_s2.json "sum(1 for p in d['posters'] if p['source']=='tvmaze')")"
check "season page is the season's, not the show's" \
  "$(jq_py tvm_s2.json "int(all('/seasons/' in p['page'] for p in d['posters'] if p['source']=='tvmaze'))")" \
  "$(jq_py tvm_s2.json "[p['page'] for p in d['posters'] if p['source']=='tvmaze']")"
check "season page differs from the show page" \
  "$(python3 -c "
import json
s={p['page'] for p in json.load(open('$WORK/tvm_s2.json'))['posters'] if p['source']=='tvmaze'}
h={p['page'] for p in json.load(open('$WORK/tvm_show.json'))['posters'] if p['source']=='tvmaze'}
print(int(bool(s) and not (s & h)))" 2>/dev/null || echo 0)"

# Television only. Asking a source a question it structurally cannot answer wastes
# a round trip and a slice of a shared per-IP rate limit.
rm -rf "${TMPDIR:-/tmp}/marquee-api-v2"
get tvm_movie.json "q=The+Matrix&type=movie&year=1999&debug=true" >/dev/null
check "movie reports tvmaze as no_data" \
  "$(jq_py tvm_movie.json "int(d['providers'].get('tvmaze')=='no_data')")" \
  "$(jq_py tvm_movie.json "d['providers']")"
check "movie makes no tvmaze call at all" \
  "$(jq_py tvm_movie.json "int(not any(c['source']=='tvmaze' for c in d['debug']['calls']))")" \
  "$(jq_py tvm_movie.json "[c['source'] for c in d['debug']['calls']]")"
check "movie response is not marked partial" \
  "$(jq_py tvm_movie.json "int(d.get('code') is None)")" "$(jq_py tvm_movie.json "repr(d.get('code'))")"
check "no tvmaze art on a movie" \
  "$(jq_py tvm_movie.json "int(not any(p['source']=='tvmaze' for p in d['posters']))")"

get tvm_coll.json "q=Star+Wars+Collection&type=collection&debug=true" >/dev/null
check "collection reports tvmaze as no_data" \
  "$(jq_py tvm_coll.json "int(d['providers'].get('tvmaze')=='no_data')")" \
  "$(jq_py tvm_coll.json "d['providers']")"
check "collection makes no tvmaze call" \
  "$(jq_py tvm_coll.json "int(not any(c['source']=='tvmaze' for c in d['debug']['calls']))")"
check "collection response is not marked partial" \
  "$(jq_py tvm_coll.json "int(d.get('code') is None)")"

# tvmaze alone: the only source that works with no credential configured.
get tvm_only.json "q=Breaking+Bad&type=show&sources=tvmaze" >/dev/null
check "sources=tvmaze skips the other three" \
  "$(jq_py tvm_only.json "int(all(v=='skipped' for k,v in d['providers'].items() if k!='tvmaze'))")" \
  "$(jq_py tvm_only.json "d['providers']")"
check "sources=tvmaze still returns posters" \
  "$(jq_py tvm_only.json "int(d['total']>0)")" "total=$(jq_py tvm_only.json "d['total']")"
check "only tvmaze art when sources=tvmaze" \
  "$(jq_py tvm_only.json "int({p['source'] for p in d['posters']} <= {'tvmaze'})")"

# A show TMDB has no tvdb_id for must still reach TVmaze through the IMDb id.
# Asserted on whichever identifier the resolved show actually has, so the check
# stays honest if TMDB's external_ids change.
check "show reached tvmaze via one of its remote ids" \
  "$(jq_py tvm_show.json "int(d['match']['tvdb_id'] is not None or d['match']['imdb_id'] is not None)")" \
  "tvdb_id=$(jq_py tvm_show.json "d['match']['tvdb_id']") imdb_id=$(jq_py tvm_show.json "d['match']['imdb_id']")"

# Ordering must not move between identical requests.
rm -rf "${TMPDIR:-/tmp}/marquee-api-v2"
get order_a.json "q=Breaking+Bad&type=show" >/dev/null
rm -rf "${TMPDIR:-/tmp}/marquee-api-v2"
get order_b.json "q=Breaking+Bad&type=show" >/dev/null
check "identical requests return identical order" \
  "$(python3 -c "
import json
a=[p['url'] for p in json.load(open('$WORK/order_a.json'))['posters']]
b=[p['url'] for p in json.load(open('$WORK/order_b.json'))['posters']]
print(int(a==b))" 2>/dev/null || echo 0)"

# TVmaze images must actually resolve.
TVURL=$(jq_py tvm_show.json "next(p['url'] for p in d['posters'] if p['source']=='tvmaze')")
C=$(curl -s -o /dev/null -w '%{http_code}' -I --max-time 15 "$TVURL")
check "tvmaze image resolves" "$([[ $C == 200 ]] && echo 1 || echo 0)" "$C  ${TVURL:0:70}"

echo
echo "=== source links (v2) ==="
# Every source carries `page`, built only from ids already held or values the source
# supplied. None is derived from a title.
rm -rf "${TMPDIR:-/tmp}/marquee-api-v2"
get pg_show.json "q=Breaking+Bad&type=show" >/dev/null
get pg_movie.json "q=The+Matrix&type=movie&year=1999" >/dev/null
get pg_season.json "q=Breaking+Bad&type=season&season=2" >/dev/null
get pg_coll.json "q=Star+Wars+Collection&type=collection" >/dev/null

for F in pg_show pg_movie pg_season pg_coll; do
  check "$F: every poster carries page" \
    "$(jq_py $F.json "int(all('page' in p for p in d['posters']))")" \
    "without=$(jq_py $F.json "sorted({p['source'] for p in d['posters'] if 'page' not in p})")"
  check "$F: page is absolute http(s)" \
    "$(jq_py $F.json "int(all(p.get('page','').startswith('http') for p in d['posters']))")"
  check "$F: page host matches its source" \
    "$(jq_py $F.json "int(all({'tmdb':'themoviedb.org','fanart.tv':'fanart.tv','thetvdb':'thetvdb.com','tvmaze':'tvmaze.com'}[p['source']] in p['page'] for p in d['posters']))")" \
    "$(jq_py $F.json "sorted({(p['source'], p['page'].split('/')[2]) for p in d['posters']})")"
done

# Season links must address the season where the source publishes one.
check "tmdb season link addresses the season" \
  "$(jq_py pg_season.json "int(all('/season/2' in p['page'] for p in d['posters'] if p['source']=='tmdb'))")" \
  "$(jq_py pg_season.json "sorted({p['page'] for p in d['posters'] if p['source']=='tmdb'})")"
check "thetvdb season link addresses the season" \
  "$(jq_py pg_season.json "int(all('/seasons/official/2' in p['page'] for p in d['posters'] if p['source']=='thetvdb'))")" \
  "$(jq_py pg_season.json "sorted({p['page'] for p in d['posters'] if p['source']=='thetvdb'})")"
check "season links differ from show links" \
  "$(python3 -c "
import json
s=json.load(open('$WORK/pg_season.json'))['posters']; h=json.load(open('$WORK/pg_show.json'))['posters']
for src in ('tmdb','thetvdb','tvmaze'):
    a={p['page'] for p in s if p['source']==src}; b={p['page'] for p in h if p['source']==src}
    if a and b and (a & b): print(0); raise SystemExit
print(1)" 2>/dev/null || echo 0)"
# fanart publishes no season page, so it correctly falls back to the series page.
check "fanart season link falls back to the series page" \
  "$(jq_py pg_season.json "int(all('/series/' in p['page'] for p in d['posters'] if p['source']=='fanart.tv'))")" \
  "$(jq_py pg_season.json "sorted({p['page'] for p in d['posters'] if p['source']=='fanart.tv'})")"

# The marker: only TVmaze's link is a licence term.
check "only tvmaze is marked attribution_required" \
  "$(jq_py pg_show.json "int({p['source'] for p in d['posters'] if p.get('attribution_required')} == {'tvmaze'})")" \
  "$(jq_py pg_show.json "sorted({p['source'] for p in d['posters'] if p.get('attribution_required')})")"
check "every tvmaze poster is marked" \
  "$(jq_py pg_show.json "int(all(p.get('attribution_required') is True for p in d['posters'] if p['source']=='tvmaze'))")"
check "marker is absent, not false, elsewhere" \
  "$(jq_py pg_show.json "int(not any('attribution_required' in p for p in d['posters'] if p['source']!='tvmaze'))")"
check "season posters marked the same way" \
  "$(jq_py pg_season.json "int({p['source'] for p in d['posters'] if p.get('attribution_required')} <= {'tvmaze'})")"

# Links must actually resolve. One per source per type, deduplicated.
python3 - "$WORK" <<'PY' > "$WORK/pages.txt"
import json,sys
seen=set(); out=[]
for f in ('pg_show','pg_movie','pg_season','pg_coll'):
    for p in json.load(open(f'{sys.argv[1]}/{f}.json'))['posters']:
        k=(p['source'], p['page'])
        if k not in seen:
            seen.add(k); out.append(f"{p['source']}\t{p['page']}")
print('\n'.join(out))
PY
while IFS=$'\t' read -r src url; do
  [[ -z $url ]] && continue
  # fanart.tv sits behind a bot challenge; a 403 there is the WAF, not a bad link.
  C=$(curl -s -o /dev/null -w '%{http_code}' -L --max-time 20 \
      -A "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36" "$url")
  if [[ $src == "fanart.tv" && $C == 403 ]]; then
    check "page resolves ($src)" 1 "$C bot-challenge, link form unverifiable here  ${url:0:56}"
  else
    check "page resolves ($src)" "$([[ $C == 200 ]] && echo 1 || echo 0)" "$C  ${url:0:60}"
  fi
done < "$WORK/pages.txt"

echo
echo "=== v1 is unaffected ==="
# The frozen version must not have acquired the new source or the new field. The
# artwork cache key is identical between versions for a given work and sources
# list, so this also proves the two stores are namespaced apart.
V1BASE="http://127.0.0.1:$PORT/marquee/api/v1/posters"
curl -s -o "$WORK/v1_show.json" -H "X-Client-Info: $(hdr)" "$V1BASE?q=Breaking+Bad&type=show"
check "v1 providers carry no tvmaze key" \
  "$(jq_py v1_show.json "int('tvmaze' not in d['providers'])")" \
  "$(jq_py v1_show.json "d['providers']")"
check "v1 posters carry no page field" \
  "$(jq_py v1_show.json "int(not any('page' in p for p in d['posters']))")"
check "v1 posters carry no attribution_required" \
  "$(jq_py v1_show.json "int(not any('attribution_required' in p for p in d['posters']))")"
check "v1 returns no tvmaze art" \
  "$(jq_py v1_show.json "int(not any(p['source']=='tvmaze' for p in d['posters']))")" \
  "$(jq_py v1_show.json "sorted({p['source'] for p in d['posters']})")"
check "v1 rejects the tvmaze token as unknown" \
  "$(curl -s -o "$WORK/v1_tok.json" -w '%{http_code}' -H "X-Client-Info: $(hdr)" "$V1BASE?q=Breaking+Bad&type=show&sources=tvmaze" | grep -q 400 && echo 1 || echo 0)" \
  "$(jq_py v1_tok.json "repr(d.get('code'))")"

echo
echo
printf '%d passed, %d failed\n' "$PASS" "$FAIL"
[[ $FAIL == 0 ]]
