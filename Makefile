# diluxone-offload — developer task runner.
#
# All PHP-based commands run inside the official `composer:2` Docker
# image by default. That keeps the host clean of plugin-specific PHP
# extensions (dom, mbstring, xml, xmlwriter, etc.) which the standard
# WSL `php-cli` build tends to lack. The Composer + dev-tooling
# versions still come from composer.lock either way, so the runtime
# difference vs CI is just the PHP-extension surface area.
#
# Usage:
#   make            # = make help
#   make install    # composer install
#   make check      # the fast gates: lint, stan, psalm, unit tests
#   make release    # check + version-alignment dry-run
#
# Override DOCKER=0 to invoke local binaries instead. Only viable on
# hosts that already have a full PHP CLI with the required extensions
# (dom, mbstring, xml, xmlwriter, libxml, openssl, json, fileinfo,
# tokenizer) and `wp` and `npx` available on $PATH.

SHELL := /bin/bash

# -- Docker plumbing ---------------------------------------------------
# Mount the project read/write at /app, run as the host user so
# composer doesn't leave root-owned files in vendor/.
DOCKER ?= 1
DOCKER_USER := $(shell id -u):$(shell id -g)
DOCKER_RUN  := docker run --rm -u $(DOCKER_USER) -v $(CURDIR):/app -w /app

# Pin floating tags via env override for reproducibility:
#   make stan COMPOSER_IMAGE=composer:2.7
COMPOSER_IMAGE ?= composer:2
WP_CLI_IMAGE   ?= wordpress:cli
PHP_IMAGE      ?= php:8.5-cli

# `--network host` lets the wordpress:cli container reach the wp-env
# MySQL on the same host. It is unsupported on Docker Desktop for
# macOS/Windows; on those hosts override DOCKER_NET= to drop it (and
# arrange WP-CLI / integration tests another way, e.g. via wp-env).
DOCKER_NET ?= --network host

ifeq ($(DOCKER),1)
COMPOSER  := $(DOCKER_RUN) $(COMPOSER_IMAGE) composer
VENDOR    := $(DOCKER_RUN) $(COMPOSER_IMAGE)
PSALM_CMD := $(DOCKER_RUN) $(PHP_IMAGE) ./vendor/bin/psalm
WP_CLI    := $(DOCKER_RUN) $(DOCKER_NET) $(WP_CLI_IMAGE)
INTEG     := $(DOCKER_RUN) $(DOCKER_NET) $(COMPOSER_IMAGE)
else
COMPOSER  := composer
VENDOR    :=
PSALM_CMD := ./vendor/bin/psalm
WP_CLI    := wp
INTEG     :=
endif

# -- Default target ----------------------------------------------------
.DEFAULT_GOAL := help

.PHONY: help
help: ## Show this help.
	@awk 'BEGIN {FS = ":.*##"; printf "\nTargets:\n"} \
	  /^[a-zA-Z0-9_-]+:.*##/ {printf "  \033[1;32m%-18s\033[0m %s\n", $$1, $$2}' \
	  $(MAKEFILE_LIST)
	@echo
	@echo "Override the Docker mode with DOCKER=0 to use local binaries."

# -- Setup -------------------------------------------------------------
.PHONY: install
install: ## Install dev dependencies (composer install).
	$(COMPOSER) install --no-interaction --prefer-dist --no-progress

.PHONY: update
update: ## Update dev dependencies (composer update).
	$(COMPOSER) update --no-interaction --prefer-dist --no-progress

# -- Linting / static analysis ----------------------------------------
.PHONY: lint
lint: ## PHPCS + WordPress Coding Standards.
	# --no-cache on purpose: PHPCS caches per file, so a file reverted to
	# contents it has already seen gets the old verdict back. That reads green
	# locally while CI, which always starts cold, is red — and half an hour
	# goes into hunting for a difference that isn't there.
	$(VENDOR) ./vendor/bin/phpcs --no-cache

.PHONY: lint-fix
lint-fix: ## Auto-fix PHPCS violations where possible.
	$(VENDOR) ./vendor/bin/phpcbf --no-cache

.PHONY: stan
stan: ## PHPStan level 8 (no baseline).
	$(VENDOR) ./vendor/bin/phpstan analyse --memory-limit=2G --no-progress

.PHONY: psalm
psalm: ## Psalm taint analysis (XSS / SQLi / RCE).
	$(PSALM_CMD) --taint-analysis --no-cache --no-progress

.PHONY: i18n
i18n: ## Regenerate languages/diluxone-offload.pot via WP-CLI.
	$(WP_CLI) i18n make-pot . languages/diluxone-offload.pot \
	    --slug=diluxone-offload \
	    --domain=diluxone-offload \
	    --exclude=tests,vendor,node_modules,.wordpress-org,assets,docs,build

# Same rule as CI's i18n job: any warning from make-pot (a string with two
# translator comments, a missing comment, a dynamic domain) fails.
.PHONY: i18n-check
i18n-check: ## Extract the strings as CI does; any make-pot warning fails.
	@mkdir -p build
	@out=$$($(WP_CLI) i18n make-pot . build/i18n-check.pot \
	    --slug=diluxone-offload \
	    --domain=diluxone-offload \
	    --exclude=tests,vendor,node_modules,.wordpress-org,assets,docs,build 2>&1); status=$$?; \
	echo "$$out"; \
	[ "$$status" -eq 0 ] || exit "$$status"; \
	if printf '%s\n' "$$out" | grep -E '^(Warning|Error):' >&2; then \
	    echo "✘ make-pot reported warnings; CI's i18n job fails on them."; exit 1; \
	fi

# -- Tests -------------------------------------------------------------
.PHONY: test
test: test-unit ## Run the unit-test suite (default — fast, no WP needed).

.PHONY: test-unit
test-unit: ## Run only the unit-test suite (no WordPress runtime).
	$(VENDOR) ./vendor/bin/phpunit --testsuite unit

# The unit suite on the oldest PHP the plugin supports, as CI runs it
# (DiluxOne/.github#85): the analysis tools run on the newest PHP alone (Psalm
# 6 needs 8.1), so they are left out and the rest is resolved for the minimum
# in a vendor of its own, build/unit-min/vendor, which tests/bootstrap.php
# loads when COMPOSER_VENDOR_DIR names it. The list is the pack's
# `php.analysis-only` (kinds/wordpress-plugin/pack.yml in DiluxOne/.github).
UNIT_MIN_PHP   := 7.4
UNIT_MIN_DIR   := build/unit-min
ANALYSIS_ONLY  := vimeo/psalm humanmade/psalm-plugin-wordpress phpstan/phpstan phpstan/extension-installer szepeviktor/phpstan-wordpress
UNIT_MIN_ENV   := -e COMPOSER=/app/$(UNIT_MIN_DIR)/composer.json -e COMPOSER_VENDOR_DIR=/app/$(UNIT_MIN_DIR)/vendor
UNIT_MIN_COMPOSER := $(DOCKER_RUN) $(UNIT_MIN_ENV) $(COMPOSER_IMAGE) composer

.PHONY: test-unit-min
test-unit-min: ## Unit suite on the oldest PHP the plugin supports (7.4), without the analysis tools, as CI.
	@mkdir -p $(UNIT_MIN_DIR) && cp composer.json $(UNIT_MIN_DIR)/composer.json
	$(UNIT_MIN_COMPOSER) remove --dev --no-update --no-interaction --quiet $(ANALYSIS_ONLY)
	$(UNIT_MIN_COMPOSER) config platform.php $(UNIT_MIN_PHP).0
	$(UNIT_MIN_COMPOSER) update --no-interaction --prefer-dist --no-progress --quiet
	$(DOCKER_RUN) $(UNIT_MIN_ENV) php:$(UNIT_MIN_PHP)-cli ./$(UNIT_MIN_DIR)/vendor/bin/phpunit -c phpunit.xml.dist

.PHONY: test-integration
test-integration: ## Run integration tests inside the wp-env tests container (needs `make env` first). PHPUNIT_ARGS="--exclude-group slow" skips the timeout tests.
	npx @wordpress/env run tests-cli wp plugin activate diluxone-offload-wordpress
	npx @wordpress/env run tests-cli \
	    ./wp-content/plugins/diluxone-offload-wordpress/vendor/bin/phpunit \
	    -c ./wp-content/plugins/diluxone-offload-wordpress/phpunit-integration.xml $(PHPUNIT_ARGS)

# -- Distribution build ------------------------------------------------
# The repo directory is diluxone-offload-wordpress (GitHub), but the plugin
# folder wordpress.org receives must be named after the slug, diluxone-offload:
# WordPress derives the text domain check from the folder name. `make dist`
# materialises exactly what ships, under the right name, applying .distignore
# so the tree has no tests, tooling or repo metadata in it.
# The dev wp-env mounts the repo root (see .wp-env.json); the Plugin Check
# environment (pcp-env) mounts only this built copy, so its checks see
# exactly what ships.
DIST_DIR := build/diluxone-offload

# A build that is not a release carries the version that is coming,
# <next>-dev.<N>: <next> from the type labels of what merged since the last
# tag (the organisation's next-version.py), N the commits since it. The
# working tree is never touched; main's markers say the last released
# version, or the one being released once its release pull request merged.
# STAMP=0 leaves the copy as the tree is (Plugin Check runs that way, like
# CI) and needs neither the script nor the network. The stamp uses GNU sed
# (Linux, WSL); on macOS install gnu-sed or build with STAMP=0.
DX_CENTRAL   ?= $(HOME)/repos/diluxone-github
NEXT_VERSION := $(if $(wildcard $(DX_CENTRAL)/scripts/next-version.py),$(DX_CENTRAL)/scripts/next-version.py,build/next-version.py)
STAMP        ?= 1

build/next-version.py:
	@mkdir -p build
	@curl -sSfL https://raw.githubusercontent.com/DiluxOne/.github/$(REVIEW_CENTRAL_REF)/scripts/next-version.py -o "$@"

# stamp <dir>: the three version markers of the copy under <dir> become the
# development version, and a `Build:` header line (absent in the tree)
# records the commit it was made from; Status › System shows both.
define stamp
	@if [ "$(STAMP)" != 0 ]; then \
	  dev="$$(python3 "$(NEXT_VERSION)" --json 2>/dev/null | python3 -c 'import json,sys; print(json.load(sys.stdin)["dev"])' 2>/dev/null)"; \
	  if [ -z "$$dev" ]; then \
	    echo "  (not stamped: next-version.py could not read GitHub; is gh logged in? The copy keeps the tree's version.)"; \
	  else \
	    build="$$(git rev-parse --short HEAD)$$(git diff --quiet HEAD -- . ':!build' || echo -dirty)"; \
	    sed -i -E "s/^( \* Version:[[:space:]]*).*$$/\1$$dev\n * Build: $$build/" "$(1)/diluxone-offload.php"; \
	    sed -i -E "s/^(define\( 'DILUXONE_OFFLOAD_VERSION', ').*(' \);)$$/\1$$dev\2/" "$(1)/diluxone-offload.php"; \
	    sed -i -E "s/^(Stable tag:[[:space:]]*).*$$/\1$$dev/" "$(1)/readme.txt"; \
	    echo "✔ Stamped $$dev, build $$build"; \
	  fi; \
	fi
endef

.PHONY: dist
dist: $(if $(filter 0,$(STAMP)),,$(NEXT_VERSION)) ## Build build/diluxone-offload/ — what gets published, stamped <next>-dev.<N> (STAMP=0: as is).
	@mkdir -p "$(DIST_DIR)"
	@# --delete, never `rm -rf` the directory itself: wp-env bind-mounts it, and
	@# replacing the inode leaves the container looking at a mount that is gone.
	@# --delete-excluded too: a file that became excluded must leave the dist,
	@# --delete alone keeps it there from an earlier build.
	@# /.dx-*/ are the organisation's working copies an autopilot run keeps
	@# beside the checkout (git-ignored through .git/info/exclude), never ours.
	@rsync -a --delete --delete-excluded --exclude-from=.distignore --exclude='build' --exclude='/.dx-*/' ./ "$(DIST_DIR)/"
	@# rsync copies the working tree, so a file git never saw (a local .env,
	@# a scratch script, a symlink to either) lands in the dist unless
	@# .distignore names it. What ships is what is committed: anything else
	@# fails the build.
	@untracked="$$(cd "$(DIST_DIR)" && find . \( -type f -o -type l \) | sed 's|^\./||' | while read -r f; do git -C "$(CURDIR)" ls-files --error-unmatch "$$f" >/dev/null 2>&1 || echo "$$f"; done)"; \
	if [ -n "$$untracked" ]; then echo "✖ Files in the dist that are not tracked by git:"; echo "$$untracked" | sed 's/^/    /'; exit 1; fi
	$(call stamp,$(DIST_DIR))
	@echo "✔ Built $(DIST_DIR) ($$(find "$(DIST_DIR)" -type f | wc -l) files)"

# -- Plugin Check (wordpress.org review gate) --------------------------
# This is the tool the plugin review team runs. PHPCS/WPCS overlaps with it
# but does not replace it: Plugin Check also enforces readme.txt structure,
# plugin headers, i18n and directory rules that WPCS knows nothing about.
#
# It runs in its own throwaway wp-env project under build/pcp, on ports
# 8896/8897 (8888-8891 belong to this repo's dev env and to other projects), mounting only the built dist. Two reasons it cannot share the main
# environment: the plugin folder there is the repo name, and Plugin Check
# compares the text domain against the folder name; and wp-env activates every
# plugin it mounts, so mounting the repo and the dist together loads the plugin
# twice and fatals on redeclaration.
PCP_DIR := $(CURDIR)/build/pcp
PCP_ENV := npx @wordpress/env --debug=false

.PHONY: pcp-env
pcp-env:
	@$(MAKE) --no-print-directory dist STAMP=0
	@mkdir -p "$(PCP_DIR)"
	@printf '%s\n' \
	  '{' \
	  '  "core": null,' \
	  '  "phpVersion": "8.5",' \
	  '  "plugins": [ "../diluxone-offload" ],' \
	  '  "port": 8896,' \
	  '  "testsPort": 8897' \
	  '}' > "$(PCP_DIR)/.wp-env.json"
	@cd "$(PCP_DIR)" && npx @wordpress/env start >/dev/null
	@cd "$(PCP_DIR)" && (npx @wordpress/env run cli wp plugin is-installed plugin-check >/dev/null 2>&1 \
	  || npx @wordpress/env run cli wp plugin install plugin-check --activate >/dev/null)

# Strict, as CI runs it (the wordpress-plugin pack of DiluxOne/.github,
# kinds/wordpress-plugin/pack.yml): its categories, the experimental checks,
# and every warning fails, as it does for the reviewer.
PCP_ARGS := --categories=plugin_repo,security,performance,accessibility,general --include-experimental --ignore-codes=stable_tag_mismatch

.PHONY: plugin-check
plugin-check: pcp-env ## Run wordpress.org's Plugin Check on the built dist, strict as CI: a warning fails.
	@cd "$(PCP_DIR)" && { out="$$(npx @wordpress/env run cli wp plugin check diluxone-offload --format=csv $(PCP_ARGS) 2>&1)"; status=$$?; \
	echo "$$out"; \
	if echo "$$out" | grep -qE ',(ERROR|WARNING),'; then echo "✖ Plugin Check found errors or warnings (strict)."; exit 1; fi; \
	if [ "$$status" -ne 0 ]; then echo "✖ Plugin Check did not run to the end (exit $$status): nothing was checked."; exit 1; fi; }

.PHONY: plugin-check-all
plugin-check-all: pcp-env ## Plugin Check on the built dist, including warnings and notices.
	@cd "$(PCP_DIR)" && npx @wordpress/env run cli wp plugin check diluxone-offload --format=table

.PHONY: plugin-check-down
plugin-check-down: ## Stop the Plugin Check environment.
	@[ ! -f "$(PCP_DIR)/.wp-env.json" ] || { cd "$(PCP_DIR)" && npx @wordpress/env stop 2>/dev/null || true; }

.PHONY: test-e2e
test-e2e: ## Run the Playwright end-to-end suite against the wp-env dev site (needs `make env` first).
	@mkdir -p build
	npx playwright test

.PHONY: coverage-e2e
coverage-e2e: ## End-to-end suite with Chromium's V8 coverage of assets/js/*.js: per-file lines and branches → build/e2e-coverage/ (summary.md, index.html, lcov.info; needs `make env` first).
	@rm -rf build/e2e-coverage && mkdir -p build
	@E2E_COVERAGE=1 npx playwright test; status=$$?; node tests/E2E/coverage/report.mjs && exit $$status

.PHONY: coverage-e2e-php
coverage-e2e-php: ## End-to-end suite with Xdebug line coverage of the plugin's PHP over every request it makes (pages, ajax, admin-post, WP-CLI) → build/e2e-php-coverage/ (clover.xml, summary.txt; restarts wp-env with Xdebug and back; needs `make env` + `make cov-image`).
	@COV_IMAGE=$(COV_IMAGE) DOCKER_USER=$(DOCKER_USER) bash tests/E2E/php-coverage/run.sh

.PHONY: screenshots
screenshots: ## Retake the wordpress.org listing screenshots (.wordpress-org/) on the dev site against a real Azure account (AZURE_E2E_ACCOUNT/KEY or .env.e2e; needs `make env`).
	npx playwright test -c playwright.screenshots.config.ts

.PHONY: test-real
test-real: ## Run the real-storage suite: every screen, single site and network, against the provider REAL_PROVIDER names (azure by default, AZURE_E2E_ACCOUNT/KEY or .env.e2e; s3 with `make s3-up` or S3_E2E_*; needs `make env` + `make env-multisite`).
	@mkdir -p build
	npx playwright test -c playwright.real.config.ts
	$(MAKE) test-integration-real

# RustFS 1.0.0, pinned by digest so every run starts the same server.
RUSTFS_IMAGE ?= rustfs/rustfs@sha256:8cc9801755448b71a786705ce76692c77e14936cccd87cf2fc31842e58f4d1ff

.PHONY: s3-up
s3-up: ## Start a local S3-compatible server (RustFS; MinIO no longer publishes images) for the S3 real suite, on wp-env's network, reachable as http://s3local:9000 from WordPress and from this machine (adds "127.0.0.1 s3local" to /etc/hosts once; needs `make env`).
	@net=$$(docker ps --filter publish=8888 --format '{{.Networks}}' | head -1); \
	 [ -n "$$net" ] || { echo "wp-env is not running: make env first"; exit 1; }; \
	 docker rm -f diluxone-offload-s3 >/dev/null 2>&1; \
	 docker run -d --name diluxone-offload-s3 --network "$$net" --network-alias s3local -p 9000:9000 \
	   -e RUSTFS_ACCESS_KEY=e2eadmin -e RUSTFS_SECRET_KEY=e2eadmin-secret $(RUSTFS_IMAGE) >/dev/null
	@grep -qE '^[0-9.]+[[:space:]]+s3local([[:space:]]|$$)' /etc/hosts || { echo "Adding '127.0.0.1 s3local' to /etc/hosts"; echo '127.0.0.1 s3local' | sudo tee -a /etc/hosts >/dev/null; }
	@for i in $$(seq 1 30); do curl -s -o /dev/null http://s3local:9000/ && break; sleep 1; done; \
	 curl -s -o /dev/null http://s3local:9000/ && echo "✔ S3-compatible server ready at http://s3local:9000 (e2eadmin / e2eadmin-secret)" || { echo "The S3 server did not start"; exit 1; }

.PHONY: s3-down
s3-down: ## Stop and remove the local S3-compatible server.
	@docker rm -f diluxone-offload-s3 >/dev/null 2>&1 && echo "✔ S3 server removed" || true

.PHONY: sweep-real
sweep-real: ## Delete the real suite's e2e-* containers older than an hour (or MAX_AGE_MINUTES=0 for all).
	@set -a; [ -f .env.e2e ] && . ./.env.e2e; set +a; \
	 node tests/E2E-real/sweep-containers.js

.PHONY: real-debug-logs
real-debug-logs: ## Copy both wp-env sites' WordPress debug logs into build/real-results/ (the real-storage jobs save them when a journey fails).
	@mkdir -p build/real-results
	-npx @wordpress/env run cli cat wp-content/debug.log > build/real-results/debug-single-site.log 2>/dev/null
	-npx @wordpress/env run tests-cli cat wp-content/debug.log > build/real-results/debug-network.log 2>/dev/null

.PHONY: test-integration-real
test-integration-real: ## PHPUnit against the real provider REAL_PROVIDER names (azure, the default: AZURE_E2E_*; s3: S3_E2E_* or the local server of `make s3-up`).
	@mkdir -p build
	@set -a; [ -f .env.e2e ] && . ./.env.e2e; set +a; \
	 if [ "$${REAL_PROVIDER:-azure}" = "s3" ]; then \
	   local_public='http://s3local:9000/{bucket}'; \
	   printf '{"preset":"%s","endpoint":"%s","region":"%s","bucket":"%s","access_key_id":"%s","secret_access_key":"%s","public_url":"%s","run_id":"%s"}\n' \
	     "$${S3_E2E_PRESET:-custom}" "$${S3_E2E_ENDPOINT:-http://s3local:9000}" "$${S3_E2E_REGION:-us-east-1}" "$${S3_E2E_BUCKET:-}" \
	     "$${S3_E2E_ACCESS_KEY_ID:-e2eadmin}" "$${S3_E2E_SECRET_ACCESS_KEY:-e2eadmin-secret}" "$${S3_E2E_PUBLIC_URL:-$$local_public}" "$${GITHUB_RUN_ID:-local}" > build/real-s3-credentials.json; \
	   chmod 600 build/real-s3-credentials.json; filter=RealS3; \
	 else \
	   [ -n "$$AZURE_E2E_ACCOUNT" ] && [ -n "$$AZURE_E2E_KEY" ] || { echo "AZURE_E2E_ACCOUNT / AZURE_E2E_KEY missing (env or .env.e2e)"; exit 1; }; \
	   printf '{"account":"%s","key":"%s","run_id":"%s"}\n' "$$AZURE_E2E_ACCOUNT" "$$AZURE_E2E_KEY" "$${GITHUB_RUN_ID:-local}" > build/real-azure-credentials.json; chmod 600 build/real-azure-credentials.json; filter=RealAzure; \
	 fi; \
	 npx @wordpress/env run tests-cli \
	    ./wp-content/plugins/diluxone-offload-wordpress/vendor/bin/phpunit \
	    -c ./wp-content/plugins/diluxone-offload-wordpress/phpunit-integration.xml --filter $$filter; \
	 status=$$?; rm -f build/real-azure-credentials.json build/real-s3-credentials.json; exit $$status

.PHONY: test-all
test-all: test-unit test-integration test-e2e ## Unit + integration + end-to-end.
	@echo "✔ All three test levels passed."

# -- Coverage ----------------------------------------------------------
# Line coverage needs pcov, which neither composer:2 nor the wp-env image
# ships; `cov-image` builds a small php:8.3-cli with pcov, mysqli, gd and
# imagick. The integration run borrows the wp-env tests container's volumes
# and network so it sees the same WordPress, database and plugin checkout.
COV_IMAGE ?= dlx-cov
TESTS_CLI  = $(shell for c in $$(docker ps --format '{{.Names}}' | grep -- '-tests-cli-1'); do \
	docker inspect $$c --format '{{range .Mounts}}{{.Destination}}{{"\n"}}{{end}}' \
	| grep -q 'plugins/diluxone-offload-wordpress$$' && echo $$c; done | head -1)
# wp-config.php reads the DB host/name/user from WORDPRESS_* env vars.
TESTS_ENV  = $(shell docker inspect $(TESTS_CLI) --format '{{range .Config.Env}}{{.}}{{"\n"}}{{end}}' 2>/dev/null | grep '^WORDPRESS_' | sed 's/^/-e /' | tr '\n' ' ')
TESTS_NET  = $(shell docker inspect $(TESTS_CLI) --format '{{range $$k,$$v := .NetworkSettings.Networks}}{{$$k}}{{end}}' 2>/dev/null)

.PHONY: cov-image
cov-image: ## Build the local coverage image (once; re-run after editing tests/docker/Dockerfile.cov).
	docker build -t $(COV_IMAGE) -f tests/docker/Dockerfile.cov tests/docker

.PHONY: coverage-unit
coverage-unit: ## Unit-test line coverage → build/clover-unit.xml.
	@mkdir -p build
	docker run --rm -u $(DOCKER_USER) -v $(CURDIR):/app -w /app $(COV_IMAGE) \
	    php -d pcov.enabled=1 -d memory_limit=512M vendor/bin/phpunit --testsuite unit --coverage-clover build/clover-unit.xml

.PHONY: coverage-integration
coverage-integration: ## Integration-test line coverage → build/clover-integration.xml (needs `make env` up).
	@test -n "$(TESTS_CLI)" || { echo "wp-env tests container not running: make env first"; exit 1; }
	@mkdir -p build
	docker run --rm -u $(DOCKER_USER) --network $(TESTS_NET) $(TESTS_ENV) --volumes-from $(TESTS_CLI) \
	    -w /var/www/html/wp-content/plugins/diluxone-offload-wordpress $(COV_IMAGE) \
	    php -d pcov.enabled=1 -d memory_limit=512M vendor/bin/phpunit -c phpunit-integration.xml --coverage-clover build/clover-integration.xml

.PHONY: coverage
coverage: coverage-unit coverage-integration ## Unit + integration coverage merged into build/clover.xml, with a per-file table.
	$(VENDOR) php tests/bin/merge-clover.php build/clover.xml build/clover-unit.xml build/clover-integration.xml

# -- Aggregate ---------------------------------------------------------
.PHONY: check
check: lint stan psalm test ## Run the fast quality gates (lint, stan, psalm, unit tests).
	@echo "✔ All checks passed."

# The organisation's local review (DiluxOne/.github, scripts/local-review.sh):
# the pull request's conventions, risk floor and Claude review, run before the
# pull request exists. The script is the organisation's own, trusted at the
# moving tag REVIEW_CENTRAL_REF, the one this repository's callers name
# (`DiluxOne/.github/...@v7` in .github/workflows/plugin-checks.yml), so what
# runs here is what CI runs; it is cloned into build/.dx-central and refreshed
# on every run. REVIEW_CENTRAL=<path> uses a checkout of your own instead, to
# try a change to the review itself.
REVIEW_CENTRAL     ?= build/.dx-central
CALLERS_REF        := $(shell sed -n 's|.*uses: DiluxOne/\.github/\.github/workflows/[^@]*@\([A-Za-z0-9._-]*\).*|\1|p' .github/workflows/plugin-checks.yml 2>/dev/null | head -1)
REVIEW_CENTRAL_REF ?= $(or $(CALLERS_REF),$(error No DiluxOne/.github ref found in .github/workflows/plugin-checks.yml: set REVIEW_CENTRAL_REF))

.PHONY: dx-central
dx-central:
	@if [ "$(REVIEW_CENTRAL)" = build/.dx-central ]; then \
	  [ -d build/.dx-central/.git ] || { rm -rf build/.dx-central && git -c advice.detachedHead=false clone -q --depth 1 --branch $(REVIEW_CENTRAL_REF) https://github.com/DiluxOne/.github build/.dx-central; }; \
	  git -C build/.dx-central fetch -q --depth 1 origin $(REVIEW_CENTRAL_REF) && git -C build/.dx-central checkout -q FETCH_HEAD; \
	  echo "DiluxOne/.github at $(REVIEW_CENTRAL_REF) ($$(git -C build/.dx-central rev-parse --short HEAD)): moving that tag changes what runs here."; \
	fi

.PHONY: review-local
review-local: dx-central ## The pull request's review before it exists: conventions, risk floor, Claude review (REVIEW_ARGS="--body-file pr.md", "--title …", "--no-claude").
	bash "$(REVIEW_CENTRAL)/scripts/local-review.sh" $(REVIEW_ARGS)

# The check "Review rules (wordpress-plugin)" of CI: the kind's rules
# (DiluxOne/.github, kinds/wordpress-plugin/rules.yml) on the shipped tree,
# every suppression listed with its reason in .github/review-suppressions.yml.
.PHONY: review-rules
review-rules: dx-central ## The kind's review rules on the shipped tree, as CI's "Review rules" check.
	@$(MAKE) --no-print-directory dist STAMP=0 >/dev/null
	$(DOCKER_RUN) $(PHP_IMAGE) php "$(REVIEW_CENTRAL)/scripts/review-rules.php" --kind wordpress-plugin --repo . --tree "$(DIST_DIR)"

# The docs job of the conventions workflow, the same checks locally: relative
# links resolve (lychee, the version lychee-action runs in CI) and no retired
# product name comes back (the regex of `retired-names:` in the review policy,
# which the workflow reads too).
LYCHEE_IMAGE ?= lycheeverse/lychee:0.24.2

.PHONY: docs-check
docs-check: ## Relative links in every Markdown file resolve, and no retired product name is back (what CI's docs job checks).
	docker run --rm -v "$(CURDIR)":/input -w /input $(LYCHEE_IMAGE) --offline --no-progress --exclude-path node_modules --exclude-path vendor --exclude-path build --exclude-path '\.dx-' './**/*.md' './.github/**/*.md'
	@retired=$$(sed -n "s/^retired-names: '\(.*\)'.*/\1/p" .github/review-policy.yml | head -1); \
	[ -n "$$retired" ] || { echo "No retired-names: line in .github/review-policy.yml to check against."; exit 1; }; \
	if git grep -nIiE "$$retired" -- . | grep -vE '^[^:]+:[0-9]+:\s*retired-names:'; then echo "A retired product name is back (see above)."; exit 1; fi; \
	echo "No retired product names."

# -- The one check before a pull request -------------------------------
# `make pre-pr` (DiluxOne/.github#479): every check this repository's pull
# request and merge queue run (plugin-checks.yml → the organisation's
# plugin-checks-wp.yml and plugin-tests-wp.yml, and the pipeline's docs job),
# one after the other, stopping at the first failure. It needs nothing
# started before it: it installs what it uses and runs the slow suites in a
# wp-env of its own, build/pre-pr on ports 8892/8893 (apart from `make env`'s
# 8888/8889, other projects' 8890/8891 and Plugin Check's 8896/8897). Whether
# a step passes or fails, that environment is destroyed at the end and
# Plugin Check's stopped, so the next run starts from the same state. What
# stays on GitHub: CodeQL, the unit tests on PHP 8.0 to 8.4 and the
# real-storage suites. The Claude review is not in it: `dx check` runs it
# after this, or `make review-local`.
GITHUB_REPO      := DiluxOne/diluxone-offload-wordpress
MAIN_FILE        := diluxone-offload.php
VERSION_CONSTANT := DILUXONE_OFFLOAD_VERSION
# The folder wp-env mounts the checkout as, inside its containers.
REPO_DIR         := $(notdir $(CURDIR))
# What the branch is compared with (the changelog, the design system).
PRE_PR_BASE      ?= origin/main
PRE_PR_DIR       := $(CURDIR)/build/pre-pr
PRE_PR_PORT      ?= 8892
PRE_PR_TESTS_PORT ?= 8893
# The config's ports, whatever a runner exports for wp-env.
PRE_PR_WPENV     := env -u WP_ENV_PORT -u WP_ENV_TESTS_PORT npx wp-env
PRE_PR_STEPS     ?= pre-pr-setup \
                    lint stan psalm design-lint \
                    i18n-form i18n-check i18n-compile \
                    test-unit test-unit-min syntax \
                    readme-check docs-check plugin-check review-rules \
                    pre-pr-env-up pre-pr-integration pre-pr-e2e

.PHONY: pre-pr
pre-pr: ## The one check before a pull request: everything its pull request and merge queue run, from a fresh clone, in an environment of its own it removes at the end.
	@trap 'status=$$?; trap - EXIT; $(MAKE) --no-print-directory pre-pr-env-down; exit $$status' EXIT; \
	trap 'exit 130' INT TERM; \
	set -e; \
	for step in $(PRE_PR_STEPS); do \
	  printf '\n── make %s ──\n' "$$step"; \
	  $(MAKE) --no-print-directory "$$step"; \
	done; \
	echo "✔ make pre-pr passed: every check of the pull request and the merge queue (CodeQL and the real-storage suites run on GitHub). The review: dx check, or make review-local."

.PHONY: pre-pr-setup
pre-pr-setup: ## What pre-pr needs on a fresh clone: composer, npm, Chromium, the organisation's scripts.
	$(MAKE) --no-print-directory install
	npm ci --no-audit --no-fund
	npx playwright install chromium
	$(MAKE) --no-print-directory dx-central

# The stylesheets this branch changes, against the design system repos.yml
# gives the repository, as CI's "Design system (stylelint)" job.
.PHONY: design-lint
design-lint: dx-central ## The CSS the branch changes against the design system, as CI's "Design system" check.
	@base=$$(git merge-base $(PRE_PR_BASE) HEAD) || { echo "✖ No merge base with $(PRE_PR_BASE): fetch it (git fetch origin main)."; exit 1; }; \
	out=$$(mktemp); trap 'rm -f "$$out"' EXIT; \
	GITHUB_OUTPUT=$$out python3 "$(REVIEW_CENTRAL)/scripts/design-lint.py" plan --repo $(notdir $(GITHUB_REPO)) --base "$$base" --head HEAD || exit 1; \
	if grep -qx 'run=true' "$$out"; then \
	  npm ci --prefix "$(REVIEW_CENTRAL)/scripts/stylelint" --ignore-scripts --no-audit --no-fund --silent && \
	  python3 "$(REVIEW_CENTRAL)/scripts/design-lint.py" check --repo $(notdir $(GITHUB_REPO)) --base "$$base" --head HEAD; \
	else echo "No stylesheet to check against the design system."; fi

# gettext (msgcat, msgfmt) for the organisation's plugin-i18n.sh, in a small
# image built once, so the host needs none.
GETTEXT_IMAGE ?= diluxone-gettext
ifeq ($(DOCKER),1)
I18N_SH = $(DOCKER_RUN) -e HOME=/tmp -v "$(abspath $(REVIEW_CENTRAL))":/central:ro $(GETTEXT_IMAGE) bash /central/scripts/plugin-i18n.sh
else
I18N_SH = bash "$(REVIEW_CENTRAL)/scripts/plugin-i18n.sh"
endif

.PHONY: gettext-image
gettext-image:
	@[ "$(DOCKER)" != 1 ] || docker image inspect $(GETTEXT_IMAGE) >/dev/null 2>&1 \
	  || printf 'FROM alpine:3.22\nRUN apk add --no-cache bash gettext git\n' | docker build -q -t $(GETTEXT_IMAGE) - >/dev/null

.PHONY: i18n-form
i18n-form: dx-central gettext-image ## languages/*.pot and *.po in the form that merges, no .mo committed (plugin-i18n.sh check).
	$(I18N_SH) check .

.PHONY: i18n-compile
i18n-compile: dx-central gettext-image ## Build languages/*.mo from the .po (never committed), as CI does before the tests.
	$(I18N_SH) compile .

# The PHP every check runs on, from the pack and the plugin's Requires PHP
# (the organisation's kind.sh and php-versions.py, as CI's "PHP versions"
# job): sets $$versions, every release from the minimum to the tools' PHP.
define php_versions
t=$$(mktemp -d); trap 'rm -rf "$$t"' EXIT; \
GITHUB_OUTPUT=$$t/kind RUNNER_TEMP=$$t DEFAULT_POLICY="$(REVIEW_CENTRAL)/policy/review-policy.default.yml" \
  KIND_DEFAULT=wordpress-plugin EVENT=push CENTRAL="$(REVIEW_CENTRAL)" bash "$(REVIEW_CENTRAL)/scripts/kind.sh" >/dev/null; \
: > $$t/php; \
SETTINGS="$$(sed -n 's/^settings=//p' $$t/kind)" MAIN=$(MAIN_FILE) README=readme.txt GITHUB_OUTPUT=$$t/php \
  python3 "$(REVIEW_CENTRAL)/scripts/php-versions.py" >/dev/null; \
versions=$$(sed -n 's/^versions=//p' $$t/php | python3 -c 'import json,sys; print(" ".join(json.load(sys.stdin)))')
endef

.PHONY: syntax
syntax: dx-central ## php -l on every shipped PHP file, on every PHP from the minimum to the tools', as CI's "PHP x.y syntax".
	@$(MAKE) --no-print-directory dist STAMP=0 >/dev/null
	@set -e; $(php_versions); \
	[ -n "$$versions" ] || { echo "✖ No PHP versions from the pack."; exit 1; }; \
	for v in $$versions; do \
	  docker run --rm -q -u $(DOCKER_USER) -v "$(CURDIR)/$(DIST_DIR)":/src:ro php:$$v-cli \
	    sh -c 'find /src -type f -name "*.php" -print0 | xargs -0 -n1 php -l >/dev/null' \
	    || { echo "✖ PHP $$v: a shipped file does not parse."; exit 1; }; \
	  echo "✔ PHP $$v syntax"; \
	done

# CI's "readme.txt and versions": the headers, the version alignment and the
# changelog entry; the changelog fragments, and readme.txt's changelog not
# edited by hand against the base; the markers against what was released
# (needs gh signed in: the type labels of what merged give the version).
.PHONY: readme-check
readme-check: dx-central ## readme.txt headers, versions, changelog fragments and release markers, as CI's "readme.txt and versions".
	@set -euo pipefail; \
	for h in "Contributors" "Tags" "Requires at least" "Tested up to" "Requires PHP" "Stable tag" "License"; do \
	  grep -qE "^$${h}:" readme.txt || { echo "✖ readme.txt: missing header $${h}"; exit 1; }; \
	done; \
	! grep -qE '^\s*\*\s*Tested up to:' $(MAIN_FILE) || { echo "✖ $(MAIN_FILE): Tested up to belongs only in readme.txt."; exit 1; }; \
	stable=$$(grep -oP '^Stable tag:\s*\K\S+' readme.txt || true); \
	version=$$(grep -oP '^\s*\*\s*Version:\s*\K\S+' $(MAIN_FILE) || true); \
	[ -n "$$version" ] || { echo "✖ $(MAIN_FILE): no Version: header."; exit 1; }; \
	base=$$(printf '%s' "$$version" | sed -E 's/-(dev|alpha|beta|rc)([.0-9]*)?$$//'); \
	if [ "$$version" = "$$base" ]; then \
	  [ "$$stable" = "$$base" ] || { echo "✖ Version $$version has no pre-release suffix, so Stable tag ($$stable) must equal it."; exit 1; }; \
	else \
	  [ "$$(printf '%s\n%s\n' "$$stable" "$$base" | sort -V | tail -1)" = "$$base" ] || { echo "✖ Version $$version is behind Stable tag $$stable."; exit 1; }; \
	fi; \
	constant=$$(grep -oP "$(VERSION_CONSTANT)',\s*'\K[^']+" $(MAIN_FILE) || true); \
	[ "$$constant" = "$$version" ] || { echo "✖ $(VERSION_CONSTANT) ($$constant) differs from Version ($$version)."; exit 1; }; \
	grep -qE "^=\s*$${stable}\s*=" readme.txt || { echo "✖ readme.txt: no '= $${stable} =' changelog entry."; exit 1; }; \
	echo "✔ readme.txt: Stable tag $$stable, Version $$version."; \
	bash "$(REVIEW_CENTRAL)/scripts/changelog-fragments.sh" check .; \
	merge=$$(git merge-base $(PRE_PR_BASE) HEAD) || { echo "✖ No merge base with $(PRE_PR_BASE): fetch it (git fetch origin main)."; exit 1; }; \
	t=$$(mktemp -d); trap 'rm -rf "$$t"' EXIT; \
	git show "$$merge:readme.txt" > "$$t/base-readme.txt" 2>/dev/null || : > "$$t/base-readme.txt"; \
	git diff --name-status --no-renames "$$merge" HEAD -- changelog.d \
	  | bash "$(REVIEW_CENTRAL)/scripts/changelog-fragments.sh" pull-request "$$t/base-readme.txt" .; \
	python3 "$(REVIEW_CENTRAL)/scripts/next-version.py" --repo $(GITHUB_REPO) --base main --json > "$$t/next.json" \
	  || { echo "✖ next-version.py could not read GitHub, so the version markers are not checked: sign gh in (gh auth login)."; exit 1; }; \
	field() { python3 -c 'import json,sys; v=json.load(open(sys.argv[1]))[sys.argv[2]]; print(str(v).lower() if isinstance(v,bool) else v)' "$$t/next.json" "$$1"; }; \
	bash "$(REVIEW_CENTRAL)/scripts/release-markers.sh" check . $(MAIN_FILE) $(VERSION_CONSTANT) "$$(field last)" "$$(field next)" "$$(field pending)"

# The slow suites' environment: the root .wp-env.json with the checkout
# mounted from build/pre-pr ("../..", so the plugin folder keeps the
# repository's name) on ports of its own.
.PHONY: pre-pr-env-config
pre-pr-env-config:
	@mkdir -p "$(PRE_PR_DIR)"
	@python3 -c 'import json,sys; c=json.load(open(".wp-env.json")); c.update(plugins=["../.."], port=int(sys.argv[1]), testsPort=int(sys.argv[2])); print(json.dumps(c, indent=2))' \
	  $(PRE_PR_PORT) $(PRE_PR_TESTS_PORT) > "$(PRE_PR_DIR)/.wp-env.json"

# Started, then the tests site made a network, as CI's integration job does
# (`multisite` is on by default and no integration-targets are declared).
.PHONY: pre-pr-env-up
pre-pr-env-up: pre-pr-env-config ## Start pre-pr's own wp-env (build/pre-pr, ports 8892/8893), its tests site a network.
	cd "$(PRE_PR_DIR)" && $(PRE_PR_WPENV) start
	cd "$(PRE_PR_DIR)" && $(PRE_PR_WPENV) run cli wp plugin activate $(REPO_DIR)
	@cd "$(PRE_PR_DIR)" && { $(PRE_PR_WPENV) run tests-cli wp core is-installed --network >/dev/null 2>&1 \
	  || $(PRE_PR_WPENV) run tests-cli wp core multisite-convert --title="Tests network"; }
	@cd "$(PRE_PR_DIR)" && { $(PRE_PR_WPENV) run tests-cli wp core is-installed --network >/dev/null 2>&1 \
	  || { echo "✖ The tests site is not a network after converting it (a database that is not fresh?)."; exit 1; }; }
	cd "$(PRE_PR_DIR)" && $(PRE_PR_WPENV) run tests-cli wp plugin activate $(REPO_DIR) --network

.PHONY: pre-pr-integration
pre-pr-integration: ## The integration suite on pre-pr's network (needs pre-pr-env-up).
	cd "$(PRE_PR_DIR)" && $(PRE_PR_WPENV) run tests-cli sh -c "cd wp-content/plugins/$(REPO_DIR) && php vendor/bin/phpunit -c phpunit-integration.xml"

.PHONY: pre-pr-e2e
pre-pr-e2e: ## The E2E suite against pre-pr's development site, with CI's retries (needs pre-pr-env-up).
	@mkdir -p build
	env -u WP_ENV_PORT -u WP_ENV_TESTS_PORT CI=1 WP_BASE_URL=http://localhost:$(PRE_PR_PORT) WP_ENV_DIR="$(PRE_PR_DIR)" npx playwright test

# Destroyed, not stopped: the E2E suite and the network conversion leave
# state in its databases and wp-config.php that a second run must not find.
.PHONY: pre-pr-env-down
pre-pr-env-down: ## Destroy pre-pr's own wp-env and stop Plugin Check's.
	-@[ ! -f "$(PRE_PR_DIR)/.wp-env.json" ] || { cd "$(PRE_PR_DIR)" && if $(PRE_PR_WPENV) destroy --force >/dev/null 2>&1; then echo "✔ pre-pr's environment destroyed"; \
	  else echo "✖ pre-pr's environment could not be destroyed: cd build/pre-pr && npx wp-env destroy --force"; fi; }
	-@$(MAKE) --no-print-directory plugin-check-down

.PHONY: test-pre-pr
test-pre-pr: dx-central ## The pre-pr target's own test (tests/bin/pre-pr-test.sh): what it runs, that it stops at a failure and always removes its environment.
	bash tests/bin/pre-pr-test.sh

# -- Local dev environment (wp-env) ------------------------------------
.PHONY: env env-up
env: env-up ## Alias of env-up.
env-up: ## Start the local wp-env Docker stack.
	npx @wordpress/env start

.PHONY: env-down
env-down: ## Stop the local wp-env Docker stack.
	npx @wordpress/env stop

.PHONY: env-clean
env-clean: ## Destroy the local wp-env Docker stack and its volumes.
	npx @wordpress/env destroy

# The tests environment is single-site by default. Converting it to a network
# is what lets tests/Integration/.../MultisiteTest.php run instead of skip; the
# single-site tests keep passing on a network, so this is a superset.
.PHONY: env-multisite
env-multisite: ## Convert the wp-env tests site into a multisite network (idempotent).
	@npx @wordpress/env run tests-cli wp core is-installed --network >/dev/null 2>&1 \
	  || npx @wordpress/env run tests-cli wp core multisite-convert --title="Tests network"
	@# The subdirectory rewrite rules WordPress asks for after the conversion:
	@# without them a site's /wp-admin/ is an Apache 404, and the real suite
	@# drives the other sites of the network through the browser.
	@npx @wordpress/env run tests-cli bash -c 'cp /var/www/html/wp-content/plugins/diluxone-offload-wordpress/tests/E2E-real/multisite.htaccess /var/www/html/.htaccess'
	@npx @wordpress/env run tests-cli wp plugin activate diluxone-offload-wordpress --network

# -- Deploy / release --------------------------------------------------
# The plugin is developed here and tried on a real site. `make deploy-test`
# copies the working tree into that site's plugins directory — only what
# ships, so no vendor/, no tests, no tooling — and leaves the site's own
# files alone. Override SITE= to try it somewhere else.
SITE ?= $(HOME)/repos/cst-website
SITE_PLUGIN := $(SITE)/wp-content/plugins/diluxone-offload
SITE_LANGS  := $(SITE)/wp-content/languages/plugins

.PHONY: deploy-test
deploy-test: $(if $(filter 0,$(STAMP)),,$(NEXT_VERSION)) ## Copy the working tree into a real site for manual smoke-testing, stamped <next>-dev.<N>.
	@if [ ! -d "$(SITE)/wp-content/plugins" ]; then \
	  echo "no site at $(SITE). Override with SITE=/path/to/wordpress"; \
	  exit 1; \
	fi
	@mkdir -p "$(SITE_PLUGIN)"
	@# --delete-excluded as well: a file that became excluded — or that was
	@# there from an earlier layout — has to leave the site copy too, or the
	@# site ends up running something the repo no longer ships.
	rsync -a --delete --delete-excluded \
	  --exclude-from=.distignore \
	  --exclude='.git' \
	  ./ "$(SITE_PLUGIN)/"
	$(call stamp,$(SITE_PLUGIN))
	@# The bundled .mo files are inert on their own: with no
	@# load_plugin_textdomain() call — discouraged by Plugin Check since
	@# WordPress 4.6 — WordPress only reads plugin translations from
	@# wp-content/languages/plugins/, which is where wordpress.org installs
	@# its language packs. Until the plugin is published and those packs
	@# exist, this puts the same files in the same place by hand.
	@mkdir -p "$(SITE_LANGS)"
	@cp languages/*.mo "$(SITE_LANGS)/" 2>/dev/null || true
	@echo "✔ Copied to $(SITE_PLUGIN) (+ $$(ls languages/*.mo 2>/dev/null | wc -l) locales in $(SITE_LANGS))"

.PHONY: release
release: check ## Pre-release validation: full quality gate + version-alignment dry-run.
	@echo "── version alignment check ─────────────────────────────"
	@PHP_VERSION=$$(grep -E '^[[:space:]]*\*[[:space:]]*Version:' diluxone-offload.php | head -1 | sed -E 's/.*Version:[[:space:]]*//'); \
	 STABLE_TAG=$$(grep -E '^Stable tag:' readme.txt | sed -E 's/Stable tag:[[:space:]]*//'); \
	 PHP_BASE=$$(echo $$PHP_VERSION | sed -E 's/-(dev|alpha|beta|rc).*$$//'); \
	 echo "  PHP header Version : $$PHP_VERSION"; \
	 echo "  PHP base (no -dev) : $$PHP_BASE"; \
	 echo "  readme Stable tag  : $$STABLE_TAG"; \
	 if [ "$$PHP_BASE" = "$$STABLE_TAG" ]; then \
	   echo "  → match ✔"; \
	 else \
	   echo "  → MISMATCH ✗ (PHP base must equal readme Stable tag at tag time)"; exit 1; \
	 fi
	@echo "✔ Ready to tag."

# -- Cleanup -----------------------------------------------------------
.PHONY: clean
clean: ## Remove caches, build artefacts, and temporary files.
	rm -rf build .phpunit.result.cache .phpunit.cache .phpcs-cache .phpstan .psalm
	@echo "✔ Cleaned."
