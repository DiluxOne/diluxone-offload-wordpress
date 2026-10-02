#!/usr/bin/env bash
# `make coverage-e2e-php`: the end-to-end suite with Xdebug line coverage of
# the plugin's PHP across every request it makes, merged into
# build/e2e-php-coverage/ (clover.xml and summary.txt, the per-file table).
#
# Restarts wp-env with --xdebug=coverage, installs collector.php as a
# must-use plugin on the dev site, runs Playwright, and then, whatever the
# outcome (a failure, Ctrl-C), removes the must-use plugin and restarts
# wp-env without Xdebug. The exit status is the suite's.
#
# Environment: COV_IMAGE (dlx-cov), DOCKER_USER (uid:gid), PLAYWRIGHT_ARGS.
set -uo pipefail

cd "$(dirname "$0")/../../.."
COV_IMAGE=${COV_IMAGE:-dlx-cov}
DOCKER_USER=${DOCKER_USER:-$(id -u):$(id -g)}
OUT=build/e2e-php-coverage
CONTAINER_PLUGIN=/var/www/html/wp-content/plugins/diluxone-offload-wordpress
MU=/var/www/html/wp-content/mu-plugins/0-diluxone-offload-php-coverage.php

docker image inspect "$COV_IMAGE" >/dev/null 2>&1 || { echo "No $COV_IMAGE image: make cov-image first."; exit 1; }

rm -rf "$OUT" && mkdir -p "$OUT/raw"

restore() {
	trap - EXIT INT TERM
	echo "Removing the coverage must-use plugin and restarting wp-env without Xdebug…"
	npx wp-env run cli rm -f "$MU" >/dev/null 2>&1
	npx @wordpress/env start >/dev/null 2>&1 || echo "wp-env did not restart cleanly: run make env."
}
trap restore EXIT
trap 'exit 130' INT TERM

npx @wordpress/env start --xdebug=coverage || exit 1
npx wp-env run cli bash -c "mkdir -p \$(dirname $MU) && cp $CONTAINER_PLUGIN/tests/E2E/php-coverage/collector.php $MU" || exit 1

# shellcheck disable=SC2086
npx playwright test ${PLAYWRIGHT_ARGS:-}
status=$?

restore

docker run --rm -u "$DOCKER_USER" -v "$PWD:$CONTAINER_PLUGIN" -w "$CONTAINER_PLUGIN" "$COV_IMAGE" \
	php -d memory_limit=1G tests/E2E/php-coverage/merge.php "$OUT/raw" "$OUT/clover-raw.xml" || exit 1
docker run --rm -u "$DOCKER_USER" -v "$PWD:/app" -w /app "$COV_IMAGE" \
	php tests/bin/merge-clover.php "$OUT/clover.xml" "$OUT/clover-raw.xml" | tee "$OUT/summary.txt"
rm -f "$OUT/clover-raw.xml"

exit $status
