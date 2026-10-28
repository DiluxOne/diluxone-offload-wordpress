#!/usr/bin/env bash
# The test of `make pre-pr` itself (DiluxOne/.github#479), run by
# `make test-pre-pr`:
#
# - `make -n pre-pr` names a step for every item of the pack's `pre-pr` list
#   and for each job of this repository's pull request and merge queue, and
#   its wp-env's ports are apart from `make env`'s and Plugin Check's;
# - with `npx` and `docker` stubbed on PATH and one step forced to fail, it
#   exits non-zero, runs no later step, and still destroys its environment.
#
# The real run is `make pre-pr` itself; nothing here starts a container.
# Needs the organisation's scripts (REVIEW_CENTRAL, build/.dx-central by
# default: `make dx-central`).
set -uo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$root"
central=${REVIEW_CENTRAL:-build/.dx-central}
[ -f "$central/scripts/pre-pr.py" ] || { echo "FAIL no DiluxOne/.github checkout at $central: make dx-central"; exit 1; }

fail=0
ok()  { echo "ok   $1"; }
bad() { echo "FAIL $1"; fail=1; }

t=$(mktemp -d)
trap 'rm -rf "$t"' EXIT

# -- What it runs ------------------------------------------------------
# Each item of the pack's list, by a word of it, and the steps that cover it.
# An item no word matches fails: the pack asks for something new.
steps=$(make --no-print-directory -n pre-pr 2>/dev/null | sed -n 's/^── make \(.*\) ──$/\1/p' | tr '\n' ' ')
[ -n "$steps" ] || bad "make -n pre-pr names its steps"
has_step() { case " $steps " in *" $1 "*) return 0 ;; esac; return 1; }

covers() { # <pack item>: the steps that cover it
  case "$1" in
    lint*)                 echo "lint design-lint" ;;
    static*)               echo "stan psalm" ;;
    unit*)                 echo "test-unit test-unit-min" ;;
    translations*)         echo "i18n-form i18n-compile" ;;
    "Plugin Check"*)       echo "plugin-check" ;;
    integration*)          echo "pre-pr-env-up pre-pr-integration" ;;
    *E2E*)                 echo "pre-pr-env-up pre-pr-e2e" ;;
    "the review rules"*)   echo "review-rules" ;;
    *)                     echo "" ;;
  esac
}

items=$(python3 "$central/scripts/pre-pr.py" covers .)
[ -n "$items" ] || bad "the pack has a pre-pr list"
while IFS= read -r item; do
  [ -n "$item" ] || continue
  want=$(covers "$item")
  if [ -z "$want" ]; then bad "no step is known to cover the pack's \"$item\""; continue; fi
  missing=""
  for s in $want; do has_step "$s" || missing="$missing $s"; done
  if [ -z "$missing" ]; then ok "the pack's \"$item\": $want"; else bad "the pack's \"$item\" lacks:$missing"; fi
done <<<"$items"

# The jobs of plugin-checks-wp.yml and plugin-tests-wp.yml (CodeQL, "What
# changed", the PHP versions and the opt-in ones left out), and the
# organisation pipeline's docs job.
for job in "PHP syntax:syntax" "unit tests:test-unit" "unit tests on the minimum:test-unit-min" \
           "PHPCS:lint" "PHPStan:stan" "Psalm:psalm" "i18n:i18n-check" "Plugin Check:plugin-check" \
           "Review rules:review-rules" "Design system:design-lint" "readme.txt and versions:readme-check" \
           "integration:pre-pr-integration" "E2E:pre-pr-e2e" "docs:docs-check"; do
  if has_step "${job#*:}"; then ok "CI's $(printf '%s' "${job%%:*}") job: ${job#*:}"; else bad "CI's ${job%%:*} job has no step (${job#*:})"; fi
done
case " $steps " in *" pre-pr-setup "*) [ "${steps%% *}" = pre-pr-setup ] && ok "the setup runs first" || bad "the setup is not first" ;; *) bad "no setup step" ;; esac
case " $steps " in *" review-local "*) bad "the Claude review is not part of pre-pr (dx check runs it)" ;; *) ok "no Claude review in it" ;; esac

# -- Stubs -------------------------------------------------------------
# npx and docker log what they are asked; the wp-env of build/pre-pr is
# "running" between its start and its destroy.
mkdir -p "$t/bin"
log=$t/log; state=$t/running
cat > "$t/bin/npx" <<'STUB'
#!/usr/bin/env bash
args="$*"; args=${args/#@wordpress\/env/wp-env}
echo "npx [$PWD] $args" >> "$STUB_LOG"
case "$PWD:$args" in
  */build/pre-pr:"wp-env start"*)
    [ ! -f "$STUB_STATE" ] || echo "started while running" >> "$STUB_LOG"
    touch "$STUB_STATE" ;;
  */build/pre-pr:"wp-env destroy"*)
    case "$args" in *--force*) rm -f "$STUB_STATE" ;; *) echo "destroy would ask" >> "$STUB_LOG"; exit 1 ;; esac ;;
esac
exit 0
STUB
cat > "$t/bin/docker" <<'STUB'
#!/usr/bin/env bash
echo "docker $*" >> "$STUB_LOG"
exit 0
STUB
chmod +x "$t/bin/npx" "$t/bin/docker"

# Steps that only say they ran, added to the Makefile through MAKEFILES (read
# by every sub-make too).
cat > "$t/steps.mk" <<'MK'
.PHONY: t-pass t-fail t-later
t-pass: ; @echo "step t-pass" >> "$(STUB_LOG)"
t-fail: ; @echo "step t-fail" >> "$(STUB_LOG)"; exit 3
t-later: ; @echo "step t-later" >> "$(STUB_LOG)"
MK

stubbed() { # <steps>: make pre-pr with those steps and the stubs; its exit status
  : > "$log"
  PATH="$t/bin:$PATH" STUB_LOG=$log STUB_STATE=$state MAKEFILES=$t/steps.mk \
    make --no-print-directory pre-pr PRE_PR_STEPS="$1" >"$t/out" 2>&1
}

# -- Its own ports -----------------------------------------------------
stubbed "pre-pr-env-up t-pass"
cfg=build/pre-pr/.wp-env.json
if [ -f "$cfg" ]; then
  read -r port tests_port mount <<<"$(python3 -c 'import json,sys; c=json.load(open(sys.argv[1])); print(c["port"], c["testsPort"], c["plugins"][0])' "$cfg")"
  read -r env_port env_tests <<<"$(python3 -c 'import json; c=json.load(open(".wp-env.json")); print(c.get("port", 8888), c.get("testsPort", 8889))')"
  clash=""
  for p in "$port" "$tests_port"; do
    for taken in "$env_port" "$env_tests" 8896 8897; do [ "$p" != "$taken" ] || clash="$clash $p"; done
  done
  [ -z "$clash" ] && [ "$port" != "$tests_port" ] && ok "its ports ($port, $tests_port) are apart from make env's ($env_port, $env_tests) and Plugin Check's" || bad "its ports clash:$clash"
  [ "$mount" = "../.." ] && ok "it mounts the checkout under the repository's name" || bad "it mounts $mount"
else
  bad "pre-pr-env-up writes $cfg"
fi

# -- A failing step ----------------------------------------------------
# Plugin Check's environment as plugin-check leaves it (its config is all
# plugin-check-down looks for).
mkdir -p build/pcp && { [ -f build/pcp/.wp-env.json ] || echo '{}' > build/pcp/.wp-env.json; }
stubbed "pre-pr-env-up t-pass t-fail t-later"; status=$?
[ "$status" -ne 0 ] && ok "a failing step fails make pre-pr (exit $status)" || bad "make pre-pr passed with a failing step"
grep -q "step t-pass" "$log" && grep -q "step t-fail" "$log" && ok "the steps before it ran" || bad "the steps before the failure did not run"
grep -q "step t-later" "$log" && bad "a step after the failure ran" || ok "no step after the failure runs"
grep -qE "^npx \[[^]]*/build/pre-pr\] wp-env destroy --force" "$log" && ok "its environment is destroyed after a failure" || bad "its environment is not destroyed after a failure"
[ ! -f "$state" ] && ok "nothing of it is left running" || bad "its environment is left running"
grep -qE "^npx \[[^]]*/build/pcp\] wp-env stop" "$log" && ok "Plugin Check's environment is stopped too" || bad "Plugin Check's environment is not stopped"
grep -q "^docker " "$log" && bad "a stubbed run called docker: $(grep -m1 '^docker ' "$log")" || ok "the stubbed run starts no container"

if [ "$fail" -eq 0 ]; then echo "all tests passed"; else cat "$t/out"; fi
exit "$fail"
