# Testing & quality

What every quality gate enforces, why, and how to run each one locally.

## Quality stack at a glance

The pull request checks run from [`.github/workflows/pull-request.yml`](../.github/workflows/pull-request.yml), which calls the shared workflows in [`DiluxOne/.github`](https://github.com/DiluxOne/.github): the **fast** suite first, then the Claude review, with the **slow** suite running alongside it. The replies to `@dilux-bot` run from [`.github/workflows/pull-request-comments.yml`](../.github/workflows/pull-request-comments.yml), which calls the shared `review-reply` workflow.

| Layer | Tool | Catches | Suite / workflow | Make target |
| --- | --- | --- | --- | --- |
| Conventions | shared `conventions` workflow, lychee | Branch name, PR title and commit headers; the description's What changes and Why, and no "Generated with …" footer; broken relative doc links; retired product names. | conventions | (runs on PR) |
| Syntax | `php -l` on PHP 7.4 to 8.5 (the shipped files) | Syntax the minimum PHP can't parse. | fast | (runs on PR) |
| Unit tests | PHPUnit + brain/monkey + mockery, same PHP matrix | Logic regressions in pure-PHP units. | fast | `make test` |
| Coding style | PHP_CodeSniffer + WordPress Coding Standards | Style, naming, escaping, sanitisation, prepared statements, deprecated APIs. | fast | `make lint` |
| Static analysis | PHPStan level 8 + szepeviktor/phpstan-wordpress | Type safety, unreachable code, undefined methods/properties, missing return types. **No baseline.** | fast | `make stan` |
| Security taint analysis | Psalm + humanmade/psalm-plugin-wordpress (taint-only mode) | XSS, SQL injection, command injection, file-system traversal. | fast | `make psalm` |
| i18n | `wp i18n make-pot` on the shipped tree | Missing translator comments, dynamic text domains, concatenated strings. | fast | `make i18n` |
| Plugin Check (wp.org) | wordpress/plugin-check on the shipped tree | The checks the wp.org plugin team runs at submission and review. | fast | `make plugin-check` |
| Readme and versions | shell | Required readme headers; `Stable tag`, `Version:` and the version constant in line (`main` holds the last released version; a `-dev.N` / `-alpha` / `-beta` / `-rc` suffix is accepted for stamped builds, see [`release.md`](release.md)); changelog entry. | fast | `make release` |
| Claude review | shared `claude-review` workflow | Everything in [`architecture.md`](architecture.md) and the WordPress review profile; rates risk and complexity. Whole change first, then only new pushes; light model for low-risk paths. | after fast | (runs on PR) |
| Claude replies | shared `review-reply` workflow | Answers a member or collaborator who mentions `@dilux-bot` in a review thread or the conversation, on a pull request from a branch of this repository; resolves its own thread when the problem is fixed or shown not to be one. | on comment | (runs on a mention) |
| Integration tests | PHPUnit + wp-env (multisite network) | Behaviour against a real WordPress runtime + DB. | slow | `make test-integration` |
| End-to-end tests | Playwright + wp-env, fake cloud client | Every admin screen driven as a user. | slow | `make test-e2e` |
| Real-storage suite | Playwright + PHPUnit against a real Azure account | Every screen and transfer, single site and network, verified byte for byte. | `tests-real-azure.yml` | `make test-real` |
| JS supply chain | CodeQL (JS) | Common JS vulnerability patterns. | `codeql.yml` | (runs when JS changes) |

**Every job above except CodeQL and the Claude replies is a required status check on `main`**, and branch protection applies to administrators too. CodeQL runs only when JavaScript changes (path filter), so it cannot be required; its alerts land in the Security tab. The replies run only when someone mentions the bot, so they are not a check at all.

The Claude review and the real-storage suite need the organisation's keys, so on pull requests from forks they are skipped (a skipped required check counts as passed). The real-storage suite then runs on `main` after the merge; see `CONTRIBUTING.md`, "What CI runs, and what it cannot run on a fork".

**What runs when.** A pull request that changes no code runs only the fast checks and the review: the integration tests, the end-to-end tests, the real-storage suite and Plugin Check show as skipped, and the ruleset accepts that. "Code" is the `code` list of the organisation's policy (`policy/review-policy.default.yml` in `DiluxOne/.github`: PHP, JS/TS, CSS, `assets/`, `templates/`, `tests/`, the composer and npm manifests, the wp-env and test configs, `Makefile`, `.distignore`, `.github/workflows/`) plus whatever this repository adds under `code:` in `.github/review-policy.yml`; `readme.txt` and hidden files trigger Plugin Check on their own. Docs, translations and the roadmap therefore cost seconds, not the twenty minutes of the full run. A push to `main` always runs everything. The answer comes from `scripts/changes.sh` in the central, run at the front of each workflow against the pull request's merge commit and the policy of the base branch; when it cannot answer, everything runs.

## Unit tests

Located in [`tests/Unit/`](../tests/Unit/). They run in pure PHP without WordPress — `brain/monkey` stubs out `__()`, `apply_filters`, etc., so a unit test can exercise a class method without booting WordPress.

```bash
make test           # default target → unit tests only
make test-unit      # explicit
```

When you add a new unit test:

- Name it after the class it tests (`CryptoTest.php` for the crypto class) and keep it next to the tests of the same area.
- Set brain/monkey up in `setUp()` and tear it down in `tearDown()`, as the existing tests do.
- Don't touch `$_GET`, `$_POST`, the database, the filesystem, or `define()` plugin constants. Move that to integration tests instead.

## Integration tests

Located in [`tests/Integration/`](../tests/Integration/). They run inside the `wp-env` Docker stack, against a real WordPress + MySQL.

```bash
make env                # boot wp-env first
make test-integration   # run the integration suite
```

Use these for code paths that genuinely depend on WordPress core: hooks, options, transients, custom tables, AJAX handlers, REST routes. Anything that boils down to "I need `wpdb`" or "I need `apply_filters` to actually apply".

The settings have tests for what they do, not only for being saved: `ForwardSyncTest` (a file above Maximum File Size never enters the initial sync), `TimeoutTest` (a live upload and a sync batch give up at the Transfer Timeout, and the health records `timeout`; the unit suite proves that a download never waits less than 300 seconds), `ForceHttpsTest` (the four URL filters, on the host the provider's URL names) and `LoggingTest` (an upload leaves no informational line with the toggle off, one with it on, and never the key).

`TimeoutTest` waits the setting's minimum, 30 seconds, twice, so it is in the `slow` group. CI runs it; locally, `make test-integration PHPUNIT_ARGS="--exclude-group slow"` leaves it out.

CI runs the same suite (the slow suite's **Integration tests (wp-env)** job) so a pure-Docker contributor can develop against the exact same environment.

## PHPCS / WordPress Coding Standards

Configuration: [`phpcs.xml.dist`](../phpcs.xml.dist).

```bash
make lint           # report violations
make lint-fix       # auto-fix what can be auto-fixed (PHPCBF)
```

The ruleset enforces the WordPress Coding Standards plus a small project-specific overlay:

- **DTOs and Enums** (`includes/DTOs/`, `includes/Enums/`) use modern PSR-12 / PascalCase, not WPCS naming. The rules that conflict with that style are excluded for those paths only.
- **Yoda conditions**, **trailing-comma-in-array**, **base64 encoding** (legitimate for crypto), and a few comment-formatting nits are globally relaxed; everything else is on.

When PHPCS reports a violation, the rule code is in the right column. Search for it in the config or in [WPCS docs](https://github.com/WordPress/WordPress-Coding-Standards/wiki) before suppressing — most warnings are real bugs (missing escaping, missing nonce, missing prepare).

## PHPStan

Configuration: [`phpstan.neon`](../phpstan.neon). Bootstrap stubs: [`phpstan-bootstrap.php`](../phpstan-bootstrap.php).

```bash
make stan
```

We run **level 8 (max strictness) with no baseline.** Every type error must be fixed in code, not suppressed. The `szepeviktor/phpstan-wordpress` extension teaches PHPStan about the WordPress API surface so e.g. `wp_remote_get()` returns `array|WP_Error` and `$wpdb->update()` returns `int|false`.

A few constants are declared `dynamicConstantNames` (`DILUXONE_OFFLOAD_DEV_MODE`, `DILUXONE_OFFLOAD_VERBOSE_LOGGING`, `WP_DEBUG`) so PHPStan does not collapse `if ( DILUXONE_OFFLOAD_DEV_MODE )` into "always false" on the bootstrap stub default. Their runtime values are user-controlled (typically from `wp-config.php`).

If you find a real type error PHPStan can't see (e.g. PHP extension stubs are missing in CI), use `// @phpstan-ignore-next-line <identifier>` with a comment explaining why. Don't add to a baseline — the project deliberately doesn't have one.

## Psalm taint analysis

Configuration: [`psalm.xml`](../psalm.xml).

```bash
make psalm
```

Psalm here runs in **taint-analysis mode only**. The `humanmade/psalm-plugin-wordpress` plugin teaches it that `esc_html()`, `esc_attr()`, `esc_url()`, `wpdb->prepare()`, `sanitize_*()` are sanitisation barriers, so user-controlled values from `$_GET` / `$_POST` / `$_REQUEST` / `$_COOKIE` / `$_FILES` / `$_SERVER` only become findings if they reach a dangerous sink (`echo`, `eval`, `exec`, `$wpdb->query()`, `file_put_contents`, `header`, …) without passing through one.

General static type-checking is suppressed in `psalm.xml` — that's PHPStan's job. Running both as type-checkers would just duplicate failures and obscure real taint findings.

If Psalm flags a path you believe is safe, the right fix is almost always to pipe the value through the appropriate WordPress escaper. Suppressing should be a last resort and must be justified inline.

## i18n validation

CI: the fast suite's **i18n** job, on the shipped tree (what `.distignore` leaves).

```bash
make i18n
```

The Makefile target runs `wp i18n make-pot` and writes the result to `build/diluxone-offload.pot`. The CI workflow does the same and additionally fails the build if any `Warning:` / `Error:` line appears in the output (WP-CLI prints them to stderr but exits 0 even when present, so we capture the output and grep ourselves).

The workflow catches three real classes of bug:

- **Missing translator comments** on `sprintf()` placeholders. WordPress requires a `/* translators: %s: ... */` comment **on the line immediately preceding** the translation function call — separating it with a blank line silently makes it invisible to gettext.
- **Conflicting translator comments** on the same msgid. If `Paused (%s)` appears in three places, all three must agree on what the placeholder means; gettext merges identical msgids.
- **Concat of translatable strings** like `__('Hello ') . __(' world')`, **dynamic text domains** like `__($string, $variable)`, and other hard-to-translate patterns.

Plugin Check (the wp.org-side validator) catches a partly overlapping but distinct subset, so both run on every PR.

## Plugin Check

CI: the fast suite's **WordPress Plugin Check** job, on the shipped tree; `make plugin-check` locally on the built dist. Runs the [official WordPress Plugin Check](https://github.com/WordPress/plugin-check-action) action with all categories enabled (`plugin_repo`, `security`, `performance`, `accessibility`, `general`) plus experimental checks. Because it checks the shipped tree, repository files (tests, docs, `.github/`) never reach it. Only `stable_tag_mismatch` is ignored, a leftover from when `main` carried a `-dev` version: Plugin Check runs on the unstamped tree (`STAMP=0`), where the three markers agree, so it never fires; the readme job enforces the marker rule and the release workflow the strict one, on the stamped tree.

If you ever submit a new version of the plugin to wp.org, the same checks run there. CI catches them earlier so a wp.org reviewer never has to.

## Running everything at once

```bash
make check     # the fast gates: lint + stan + psalm + unit tests
make release   # make check + version-alignment dry-run
```

`make check` is the pre-push habit; it does not replace CI. Integration, E2E, i18n and Plugin Check have their own targets, and the real-storage suite needs credentials. `make release` is what the maintainer runs on `main` before approving a release (see [`release.md`](release.md)); there is no release-prep pull request.
