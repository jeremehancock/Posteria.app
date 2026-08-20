#!/usr/bin/env bash
# Verification for router.php.
#
#   bash tests/router_test.sh
#
# The router is load-bearing: nixpacks starts the deployment with it, so a mistake
# here takes the whole site down rather than one endpoint. This suite therefore
# checks both halves of its job — that internal paths are denied, and that
# everything else is served exactly as it was before the router existed.
#
# The second half matters more than the first. The endpoints resolve through the
# built-in server's directory-index behaviour (/marquee/api/v2/posters -> that
# directory's index.php), and a router that handled requests itself instead of
# returning false would silently break every one of them.
#
# Needs no credentials: a 401 from a poster endpoint proves it was reached and ran,
# which is the only thing being tested here.

set -uo pipefail

PORT=${PORT:-8796}
ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
BASE="http://127.0.0.1:$PORT"
WORK=$(mktemp -d)
PASS=0
FAIL=0

cleanup() {
  [[ -n ${SRV_PID:-} ]] && kill "$SRV_PID" 2>/dev/null
  rm -rf "$WORK"
}
trap cleanup EXIT

hdr() {
  printf '{"name":"Marquee","version":"router-test","ts":%s}' "$(date +%s%3N)" | base64 -w0
}

chk() { # chk <expected-status> <path> [note]
  local exp=$1 path=$2 note=${3:-}
  local got
  got=$(curl -s -o /dev/null -w '%{http_code}' -H "X-Client-Info: $(hdr)" "$BASE$path")
  if [[ $got == "$exp" ]]; then
    PASS=$((PASS + 1)); printf '  \033[32mPASS\033[0m  %-58s %s %s\n' "$path" "$got" "$note"
  else
    FAIL=$((FAIL + 1)); printf '  \033[31mFAIL\033[0m  %-58s got %s, want %s %s\n' "$path" "$got" "$exp" "$note"
  fi
}

echo "Starting server on :$PORT with router.php (docroot $ROOT)"
php -S "127.0.0.1:$PORT" -t "$ROOT" "$ROOT/router.php" >"$WORK/server.log" 2>&1 &
SRV_PID=$!
sleep 2

echo
echo "=== the site and the endpoints are unaffected ==="
# Directory-index resolution is the thing most easily broken by a router.
chk 200 "/"                              "site root"
chk 200 "/index.html"
chk 200 "/posteria.html"
chk 200 "/marquee/api/v2/time"           "directory -> index.php"
chk 200 "/marquee/api/v1/time"           "frozen v1 still served"
# 400, not 401: these requests carry a valid X-Client-Info header, so they clear
# CORS, the method check, identification and the rate limiter, and get as far as
# parameter validation — which rejects them for the `q` and `type` this suite does
# not send. A machine-readable 400 from the endpoint's own parser is the strongest
# available proof that the router handed the request all the way through.
chk 400 "/marquee/api/v2/posters"        "reached its parameter validation"
chk 400 "/marquee/api/v1/posters"
chk 401 "/api/fetch/posters"             "legacy endpoint, its own auth"
chk 200 "/api/time.php"

echo
echo "=== library files stay unreachable, as they were before ==="
# These 404 from their own MARQUEE_API_V* guard, not from the router. Asserted so a
# future router change cannot accidentally start serving them as source text.
chk 404 "/marquee/api/v2/lib/config.php"
chk 404 "/marquee/api/v2/lib/tvmaze.php"

echo
echo "=== internal paths are denied ==="
chk 404 "/openspec/specs/marquee-poster-sources/spec.md"  "planning docs"
chk 404 "/openspec/"
chk 404 "/openspec/changes/archive/"
chk 404 "/marquee/api/v2/tests/verify_live.sh"            "test scripts"
chk 404 "/marquee/api/v1/tests/verify_live.sh"
chk 404 "/marquee/api/v2/tests/resolve_test.php"
chk 404 "/nixpacks.toml"                                  "deploy descriptor"
chk 404 "/router.php"                                     "the router itself"

echo
echo "=== dot-prefixed paths are denied ==="
# .git is not shipped by nixpacks, so this is defence in depth rather than a live
# exposure. .gitignore and .claude are tracked or present locally.
chk 404 "/.git/config"
chk 404 "/.git/HEAD"
chk 404 "/.gitignore"

echo
echo "=== the denial cannot be walked around ==="
chk 404 "/openspec//specs/marquee-poster-sources/spec.md"  "doubled separator"
chk 404 "/OpenSpec/specs/marquee-poster-sources/spec.md"   "case"
chk 404 "/openspec%2Fspecs/marquee-poster-sources/spec.md" "encoded separator"
chk 404 "/marquee/api/v2/tests/"                           "bare test directory"

echo
echo "=== a filename containing a dot is not mistaken for a dot segment ==="
chk 200 "/posteria.html"  "dot is inside the name, not after a separator"

echo
echo
printf '%d passed, %d failed\n' "$PASS" "$FAIL"
[[ $FAIL == 0 ]]
