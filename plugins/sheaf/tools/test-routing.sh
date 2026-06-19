#!/usr/bin/env bash
#
# Routing regression tests for Sheaf.
#
# Re-seeds the dev site to a known state, then asserts the nested-URL routing
# invariants — per-book slug discrimination, wrong-book 404s, section handling
# and the agreed sample URLs. Run it from the host (it uses curl + the wpenv
# wrapper):
#
#   plugins/sheaf/tools/test-routing.sh
#
# Override the site with BASE, e.g. BASE=http://localhost:8888 ...
set -u

BASE="${BASE:-http://localhost:8888}"
WPENV="${WPENV:-/usr/local/bin/wpenv}"
pass=0
fail=0

ok()  { pass=$((pass + 1)); printf '  \033[32mok\033[0m   %s\n' "$1"; }
ng()  { fail=$((fail + 1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }

# check_status <path> <expected-code>
check_status() {
	local code
	code="$(curl -s -o /dev/null -w '%{http_code}' "$BASE/$1/")"
	if [ "$code" = "$2" ]; then ok "[$code] /$1/"; else ng "[$code, want $2] /$1/"; fi
}

# check_contains <path> <substring> <label>
check_contains() {
	if curl -s "$BASE/$1/" | grep -q "$2"; then ok "/$1/ — $3"; else ng "/$1/ — $3"; fi
}

echo "Seeding known state…"
"$WPENV" run cli wp eval-file wp-content/plugins/sheaf/tools/seed.php >/dev/null 2>&1

echo "Pages and book/series indexes (expect 200):"
for u in novels novels/long-war novels/long-war/embers novels/long-war/ashfall \
         novels/clockwork novels/clockwork/clockwork-heart novels/clockwork/iron-wind \
         novels/wintering novels/agreement-with-hell fiction fiction/asterism \
         fiction/asterism/ship-design about about/met title-text; do
	check_status "$u" 200
done

echo "Chapters and sections (expect 200):"
for u in novels/clockwork/clockwork-heart/3 novels/clockwork/iron-wind/prologue \
         novels/clockwork/iron-wind/12-skyfire novels/clockwork/clockwork-heart/part-i-wind-up; do
	check_status "$u" 200
done

echo "Bad paths (expect 404):"
check_status "novels/long-war/embers/nonesuch" 404
# Wrong book: this slug exists only in Embers, not Ashfall — must not leak across.
check_status "novels/long-war/ashfall/13-resignations" 404

echo "Per-book slug discrimination — five 'prologue' URLs must be five distinct posts:"
distinct="$(for u in novels/long-war/embers/prologue novels/long-war/ashfall/prologue \
                     novels/clockwork/clockwork-heart/prologue novels/clockwork/iron-wind/prologue \
                     novels/wintering/prologue; do
	curl -s "$BASE/$u/" | grep -oP 'postid-\d+' | head -1
done | sort -u | wc -l)"
if [ "$distinct" = "5" ]; then ok "5 distinct prologue posts"; else ng "expected 5 distinct prologue posts, got $distinct"; fi

echo "Each prologue breadcrumbs to its own book:"
check_contains "novels/long-war/embers/prologue"   "Embers"   "breadcrumb names Embers"
check_contains "novels/long-war/ashfall/prologue"  "Ashfall"  "breadcrumb names Ashfall"
check_contains "novels/clockwork/iron-wind/prologue" "Iron Wind" "breadcrumb names Iron Wind"

echo "Section view carries the CSS hook:"
check_contains "novels/clockwork/clockwork-heart/part-i-wind-up" "sheaf-section" "body class sheaf-section"

echo "Data-layer checks:"
n="$("$WPENV" run cli wp eval 'echo count(get_posts(["post_type"=>"sheaf_chapter","name"=>"prologue","post_status"=>"publish","numberposts"=>-1]));' 2>/dev/null | tr -dc '0-9')"
if [ "$n" = "5" ]; then ok "5 chapters stored with the slug 'prologue'"; else ng "expected 5 'prologue' slugs, got $n"; fi

w="$("$WPENV" run cli wp eval 'echo \Sheaf\Words::count_in("<p>one two three</p>[sheaf_toc]");' 2>/dev/null | tr -dc '0-9')"
if [ "$w" = "3" ]; then ok "Words::count_in strips markup/shortcodes (=3)"; else ng "Words::count_in expected 3, got $w"; fi

echo
echo "Passed: $pass   Failed: $fail"
[ "$fail" -eq 0 ]
