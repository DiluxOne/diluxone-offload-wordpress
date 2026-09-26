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
PHP_IMAGE      ?= php:8.3-cli

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

# -- Tests -------------------------------------------------------------
.PHONY: test
test: test-unit ## Run the unit-test suite (default — fast, no WP needed).

.PHONY: test-unit
test-unit: ## Run only the unit-test suite (no WordPress runtime).
	$(VENDOR) ./vendor/bin/phpunit --testsuite unit

.PHONY: test-unit-min
test-unit-min: ## Unit suite under the oldest PHP the plugin supports (7.4): what CI's matrix runs and the composer image does not.
	docker run --rm -v "$(CURDIR)":/app -w /app php:7.4-cli php vendor/bin/phpunit -c phpunit.xml.dist

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
# working tree is never touched; main stays at the last released version.
# STAMP=0 leaves the copy as the tree is (Plugin Check runs that way, like CI).
DX_CENTRAL   ?= $(HOME)/repos/diluxone-github
NEXT_VERSION := $(if $(wildcard $(DX_CENTRAL)/scripts/next-version.py),$(DX_CENTRAL)/scripts/next-version.py,build/next-version.py)
STAMP        ?= 1

build/next-version.py:
	@mkdir -p build
	@curl -sSfL https://raw.githubusercontent.com/DiluxOne/.github/v1/scripts/next-version.py -o "$@"

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
dist: $(NEXT_VERSION) ## Build build/diluxone-offload/ — what gets published, stamped <next>-dev.<N> (STAMP=0: as is).
	@mkdir -p "$(DIST_DIR)"
	@# --delete, never `rm -rf` the directory itself: wp-env bind-mounts it, and
	@# replacing the inode leaves the container looking at a mount that is gone.
	@# --delete-excluded too: a file that became excluded must leave the dist,
	@# --delete alone keeps it there from an earlier build.
	@rsync -a --delete --delete-excluded --exclude-from=.distignore --exclude='build' ./ "$(DIST_DIR)/"
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

.PHONY: plugin-check
plugin-check: pcp-env ## Run wordpress.org's Plugin Check on the built dist.
	@cd "$(PCP_DIR)" && npx @wordpress/env run cli wp plugin check diluxone-offload --format=table --severity=5

.PHONY: plugin-check-all
plugin-check-all: pcp-env ## Plugin Check on the built dist, including warnings and notices.
	@cd "$(PCP_DIR)" && npx @wordpress/env run cli wp plugin check diluxone-offload --format=table

.PHONY: plugin-check-down
plugin-check-down: ## Stop the Plugin Check environment.
	@cd "$(PCP_DIR)" && npx @wordpress/env stop 2>/dev/null || true

.PHONY: test-e2e
test-e2e: ## Run the Playwright end-to-end suite against the wp-env dev site (needs `make env` first).
	@mkdir -p build
	npx playwright test

.PHONY: screenshots
screenshots: ## Retake the wordpress.org listing screenshots (.wordpress-org/) on the dev site against a real Azure account (AZURE_E2E_ACCOUNT/KEY or .env.e2e; needs `make env`).
	npx playwright test -c playwright.screenshots.config.ts

.PHONY: test-real
test-real: ## Run the real-storage suite: every screen, single site and network, against a real Azure account (AZURE_E2E_ACCOUNT/KEY or .env.e2e; needs `make env` + `make env-multisite`).
	@mkdir -p build
	npx playwright test -c playwright.real.config.ts
	$(MAKE) test-integration-real

.PHONY: sweep-real
sweep-real: ## Delete the real suite's e2e-* containers older than an hour (or MAX_AGE_MINUTES=0 for all).
	@set -a; [ -f .env.e2e ] && . ./.env.e2e; set +a; \
	 node tests/E2E-real/sweep-containers.js

.PHONY: test-integration-real
test-integration-real: ## PHPUnit against the real Azure provider (same credentials as test-real).
	@mkdir -p build
	@set -a; [ -f .env.e2e ] && . ./.env.e2e; set +a; \
	 [ -n "$$AZURE_E2E_ACCOUNT" ] && [ -n "$$AZURE_E2E_KEY" ] || { echo "AZURE_E2E_ACCOUNT / AZURE_E2E_KEY missing (env or .env.e2e)"; exit 1; }; \
	 printf '{"account":"%s","key":"%s","run_id":"%s"}\n' "$$AZURE_E2E_ACCOUNT" "$$AZURE_E2E_KEY" "$${GITHUB_RUN_ID:-local}" > build/real-azure-credentials.json; chmod 600 build/real-azure-credentials.json
	npx @wordpress/env run tests-cli \
	    ./wp-content/plugins/diluxone-offload-wordpress/vendor/bin/phpunit \
	    -c ./wp-content/plugins/diluxone-offload-wordpress/phpunit-integration.xml --filter RealAzure; \
	 status=$$?; rm -f build/real-azure-credentials.json; exit $$status

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
deploy-test: $(NEXT_VERSION) ## Copy the working tree into a real site for manual smoke-testing, stamped <next>-dev.<N>.
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
