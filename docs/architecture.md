# Architecture

How DiluxOne Offload is built, the rules its code follows, and what a review of it looks for. This is the single source: people read it, coding agents read it (through [`AGENTS.md`](../AGENTS.md)), and the automated Claude review reads it on every pull request. When the code changes one of these facts, this file changes in the same pull request.

When in doubt, prefer the project's existing patterns over textbook WordPress patterns.

## What this repo is

WordPress plugin that offloads media files to cloud object storage and
serves them back transparently. Two providers ship: **Azure Blob
Storage** and **S3-compatible storage** (Amazon S3, Cloudflare R2,
Backblaze B2, DigitalOcean Spaces, Wasabi, Google Cloud Storage with HMAC
keys, MinIO), both with your own credentials. The plugin is GPL-2.0-or-later
with no paid tier and no feature held back.

**The plugin's distinguishing technical decision** is the use of a PHP
**stream wrapper** to intercept every read and write to
`/wp-content/uploads/`. This means **no URL rewriting** in post content,
**no database migration** of attachment URLs, and full compatibility with
WooCommerce, page builders, image editors, and any plugin that uses
standard PHP filesystem functions (`fopen`, `file_get_contents`, `unlink`,
etc.). Keep this principle in mind: any proposal that would require
rewriting URLs, post content, or database rows is almost certainly the
wrong direction.

---

## Architecture quick-reference

PHP namespace: `DiluxOneOffload\`. Class autoloading is via a custom
mapping in `includes/enhanced-autoloader.php` (NOT Composer, NOT PSR-4
strict). The mixed file-naming style (`class-diluxone-offload-*.php` for older files,
`PascalCase.php` for newer DTOs/Enums) is intentional — the autoloader
handles both.

| Path | Contains |
|------|----------|
| `diluxone-offload.php` | Main plugin file. Defines constants, registers autoloader, bootstraps `Plugin`. |
| `includes/class-diluxone-offload-plugin-enhanced.php` | `Plugin` — top-level orchestration, modern AJAX handlers (sync engine moved here from `Admin`). |
| `includes/class-diluxone-offload-admin.php` | `Admin` — admin UI, menu, page rendering, AJAX handlers, settings save/load, connection-health banner. |
| `includes/class-diluxone-offload-cloud-stream-wrapper.php` | `CloudStreamWrapper` — implements PHP stream wrapper. Performance-critical. |
| `includes/class-diluxone-offload-config-manager.php` | `ConfigManager` — single source of truth for plugin config + connection-health state. Encrypts credentials at rest via `Crypto`. |
| `includes/class-diluxone-offload-crypto.php` | `Crypto` — AES-256-GCM, key derived from WP salts. |
| `includes/class-diluxone-offload-logger.php` | `Logger` — the ONLY place `error_log()` is called. Dedupe + level-gated. |
| `includes/class-diluxone-offload-sync-manager.php` | `SyncManager` — sync state machine, parallel/chunked uploads. |
| `includes/class-diluxone-offload-db.php` | DB layer for sync state custom table. Uses `$wpdb->prepare()` everywhere. |
| `includes/class-diluxone-offload-validation-helper.php` | `ValidationHelper` — input validation utilities. |
| `includes/class-diluxone-offload-mime-helper.php` | `MimeHelper` — extension → MIME mapping. |
| `uninstall.php` | Uninstall logic: drops the plugin's options, transients and table (per site on multisite). |
| `includes/interfaces/interface-cloud-storage-client.php` | `CloudStorageClientInterface` — contract for all providers. Besides the file operations and the transfer handles the sync engine runs, each provider answers `get_storage_stats()`, `describe_error_body()` (the error code and message of a response, never the signature some services echo) and `verify_upload_response()` (whether a transfer the engine ran succeeded, and the line to record when not), so nothing outside `includes/providers/` asks which provider it is talking to. A chunked upload handle may carry `on_failure`, a callable the sync engine runs when that transfer fails (the S3 provider aborts its multipart upload there, so the parts already sent are not kept and billed). |
| `includes/factories/class-cloud-storage-factory.php` | `CloudStorageFactory::create($provider, $config)`. |
| `includes/providers/class-azure-provider.php` | `AzureProvider` — Azure Blob Storage REST API. |
| `includes/providers/trait-storage-stats.php` | `StorageStats` — the usage stats every provider computes the same way from its own listing, cached in the transient `ConfigManager::STATS_TRANSIENTS` names for it. |
| `includes/providers/class-s3-compatible-provider.php` | `S3CompatibleProvider` — the S3 REST API with SigV4, for every S3-compatible service. Path-style or virtual-hosted addressing, parts of 5 MiB, Content-MD5 on every PUT of file bytes (the service verifies it), downloads through the signed endpoint, Test Connection as a probe written, read back anonymously at the Public URL and deleted. |
| `includes/providers/class-s3-presets.php` | `S3Presets` — the one table of services (endpoint, region rule, addressing, public URL pattern, whether an object ACL applies), handed as is to the Connection form's JavaScript. |
| `includes/providers/class-aws-signature-v4.php` | `AwsSignatureV4` — AWS Signature Version 4 for the S3-compatible provider, tested against the vectors AWS publishes (`tests/fixtures/sigv4/`). |
| `includes/Enums/class-plugin-state.php` | `Enums\PluginState` (string constants, NOT PHP 8.1 enum — PHP 7.4 minimum). |
| `includes/Enums/SyncStatus.php` | `Enums\SyncStatus`. |
| `includes/DTOs/*.php` | Value objects with `->toArray()` — `PluginConfig`, `PluginSettings`, `ProviderConfig`, `AzureConfig`, `ConnectionResult`, `FileInfo`, `OperationResult`, `UploadResult`, `SyncFilter`. `ProviderConfig` also knows each provider's form fields (`FORM_FIELDS`), the rows the read-only screens show (`describe()`, never the secret) and the `fingerprint()` of a complete configuration. |
| `includes/class-diluxone-offload-image-editor-{gd,imagick}.php` | Image-editor adapters that play nicely with the stream wrapper. |
| `templates/admin-*.php` | Admin views (rendered by `Admin::render_screen_content()`). One file per screen or tab: `admin-overview.php`; `admin-provider-{connection,credentials}.php`; `admin-sync-{sync,offloading,disconnect}.php`; `admin-settings-{transfers,serving,logging}.php`; `admin-status-{health,system}.php`. `templates/partials/` holds the rail beside every screen and the sync modal the three Sync & Offloading tabs share. The screens, their submenu slugs and their tabs are one list, `Admin::screens()`; the old `&tab=` URLs redirect through `Admin::legacy_tab()`. |
| `assets/css/admin.css`, `assets/js/admin.js` | Plugin runtime assets bundled with the plugin. |
| `languages/*.{po,mo,pot}` | Text domain `diluxone-offload`. Only the `.pot` ships; the `.po`/`.mo` for `es_AR`, `es_ES`, `es_MX`, `pt_BR`, `pt_PT`, `fr_FR`, `de_DE`, `it_IT` stay in the repo as the source for translate.wordpress.org (see `.distignore`). |
| `languages/readme/` | The wordpress.org readme in the same eight locales (`readme.pot`, `readme-<locale>.po`), imported by hand into the Stable Readme project on translate.wordpress.org. Never ships (`.distignore`). |
| `.wordpress-org/` | Banner / icon / screenshots for the wp.org listing. NOT runtime assets. |

---

## Plugin state machine — the canonical flow

Defined in `Enums\PluginState`. Valid states only:

```
NOT_CONFIGURED → CONFIGURED → SYNCING → SYNCED → OFFLOADING_ACTIVE
```

Transitions:
- `NOT_CONFIGURED → CONFIGURED`: user enters provider credentials and they validate.
- `CONFIGURED → SYNCING`: user clicks **Start Sync**.
- `SYNCING → SYNCED`: sync completes.
- `SYNCED → OFFLOADING_ACTIVE`: user enables offloading; stream wrapper takes over.
- `OFFLOADING_ACTIVE → SYNCED`: user deactivates offloading.

There is deliberately no `DISCONNECTING` and no `ERROR` state. Reverse sync (downloading files back from the cloud) runs while the plugin stays in `OFFLOADING_ACTIVE`; when it finishes, the user clicks **Deactivate Offloading** to go back to `SYNCED`. Errors live at the file level (sync failures) and at the connection level (connection health), never at the plugin level.

If a PR proposes adding a new top-level state, push back unless there is a concrete user-visible scenario that the existing five states cannot represent.

---

## Connection health model

Three small options sit next to it, written by the code that knows and read by the screens: `diluxone_offload_timestamps` (`connected_at`, `state_changed_at`, `offloading_since`; `ConfigManager::get_timestamps()`, written by `save_provider_config()` when the provider, account or container changes and by `set_state()` on a real change), `diluxone_offload_last_upload` (`path`, `size`, `time`; written by the stream wrapper after a successful upload, which also records the file in the tracking table as synced with no local copy; the wrapper's `unlink()` and `rename()` keep the table true too) and `diluxone_offload_skipped` (the last scan's time, total and paths per reason, capped at 500 paths; written by `SyncManager::scan_files_to_sync()`). Delete Local Files leaves the tracking table's rows as `synced = 1, deleted = 1`; only a cancel, a resync, a scratch reverse sync and the provider's removal clear it.

Stored in WP option `diluxone_offload_connection_health`. The shape:

```php
[
    'status'               => string, // 'unknown', 'healthy' or 'unhealthy'
    'last_check'           => int,    // timestamp
    'last_success'         => int,    // timestamp
    'error_code'           => string, // e.g. 'decrypt_failed', '401', '403', '404', 'timeout'
    'error_message'        => string,
    'error_source'         => string, // where it was detected, e.g. 'health_check', 'upload', 'list_files', 'crypto'
    'consecutive_failures' => int,
]
```

Conventions:
- **A `decrypt_failed` is recorded once per failure cycle.** `ConfigManager::record_connection_failure()` counts every call; the decrypt path in `ConfigManager::get_config()` checks that the health is not already `unhealthy` with `decrypt_failed` before calling it, so the counter does not grow on every page load.
- **After `consecutive_failures >= 3` the stream wrapper refuses writes** (`CloudStreamWrapper::writes_allowed()`): the upload fails and WordPress reports it. There is **no local fallback** — nothing is ever written to `uploads/` on the server instead. Any proposal to add one is a defect. The threshold is the agreed safety valve; don't change it without discussion.
- **An upload that goes through ends a pause.** Both the stream wrapper (an upload through WordPress) and the sync (`SyncManager::process_batch()`, a round with at least one upload accepted) call `ConfigManager::record_connection_success()` when the health is `unhealthy`: a key fixed since the failures is healthy again as soon as it works, not at the next five-minute check.
- **A transient answer is asked again, a wrong request never.** Every request the providers make goes through `TransientRetry::send_with_retry()` (`includes/providers/trait-transient-retry.php`): a 500, 502, 503 or 504, or a connection that dropped, is sent again up to three times in all, after 0.25 s and 0.75 s. A 4xx (the request or the key is wrong) and a timeout (its time is already spent, in the owner's browser) are not. Backblaze B2 answers 500 to about one upload in a hundred and documents that clients retry; S3, R2, Google and Azure document the same, and their SDKs do it. Only the last answer counts for the connection health. The sync's parallel uploads (`curl_multi`) are not covered here: a failed file is picked up again by the next round, up to three times (`get_pending_files()`, `errors < 3`).
- **Two helpers map `error_code` to user-facing copy**, both in `Admin`: `pause_reason_short(string $error_code): string` and `health_banner_copy(string $error_code, string $error_message): array`. The same vocabulary appears in the banner, the Status cards, and the Overview cards. If you add a new error_code, update BOTH helpers.

---

## Hard rules

### Security (highest priority)

- **All AJAX endpoints** must call `check_ajax_referer('diluxone_offload_admin', 'nonce')` AND `current_user_can('manage_options')`. The pattern is established and consistent across `Admin::ajax_*` methods. Any new AJAX action without both checks is a defect.
- **All `$_POST` / `$_GET` input** must be sanitized: `sanitize_text_field`, `esc_url_raw`, `sanitize_email`, or `wp_kses` for HTML. Never trust raw `$_POST['x']`.
- **All output** must be escaped: `esc_html`, `esc_attr`, `esc_url`, `esc_textarea`. Never echo a variable into HTML without escaping.
- **All SQL** must use `$wpdb->prepare()`. Concatenating user input into SQL strings is a defect, even when the value "looks safe". `class-diluxone-offload-db.php` is the reference for the correct pattern.
- **Credentials must NEVER appear in logs.** This includes the Azure access key and the S3 secret access key, decrypted plaintext credentials, and anything in the `provider_config` array. The pattern `Logger::error('failed: ' . print_r($config, true))` is forbidden — that array contains the credential. When logging connection failures, log the `error_code` and a sanitized `error_message`, not the full payload. The connection-health system is designed for this purpose; use it.
- **Encryption is AES-256-GCM with a key derived from WP salts** (`wp_salt('auth') . wp_salt('secure_auth')` via HMAC-SHA256). Do not propose downgrading the cipher, removing GCM authentication, switching to CBC, accepting a plaintext fallback, or persisting the key anywhere. The `Crypto::encrypt()` / `Crypto::decrypt()` interface is stable; if a credential cannot be decrypted, `decrypt()` returns `null` and the caller surfaces the failure to the user (`decrypt_failed` connection-health event). There is intentionally no fallback to plaintext storage.
- **Only a configuration that passed Test Connection is saved.** Test Connection stores, per user and for five minutes, the `ProviderConfig::fingerprint()` (a SHA-256 of the whole configuration, secret included, never the secret itself); the Connection form's save and the Credentials tab's save recompute it from what they are about to store and refuse on any difference (`Admin::passed_connection_test()`).
- **The plugin deletes objects it did not write only in one place, and narrowly.** Before the first sync (state `configured`, tracking table empty) the Sync tab lists this site's prefix and, when the owner types the container or bucket name, deletes what is under it (`Plugin::ajax_empty_target()`, `SyncManager::empty_target()`). Never outside the prefix (`CloudStreamWrapper::site_files()`: other sites of a network, other folders), never once a sync ran (checked again before every delete, so a sync started from another tab stops it), never without the typed name, compared with `hash_equals()`. An empty tracking table is what makes a target untouched, so the provider cannot be deleted (which empties the table) once offloading is on and the cloud may hold the only copies (`Admin::can_delete_provider()`, enforced in `ajax_remove_provider()` as well as on the screen). Any other path that deletes an object the tracking table does not know is a defect.
- **The `DILUXONEOFFLOADENC1:` prefix on encrypted values is a versioning hint.** A future key rotation may bump it. Don't strip it, parse it manually, or assume specific positions of bytes after it.

### WordPress conventions

- All user-facing strings must be wrapped in WordPress translation functions (`__()`, `_e()`, `_n()`, `esc_html__()`, `esc_html_e()`, etc.) with the text domain `diluxone-offload`. Translations come from translate.wordpress.org — a hardcoded English string can never be translated.
- `current_user_can('manage_options')` is the standard capability check for admin operations. We don't define custom capabilities (yet).
- Multisite-aware: configuration is per site, always. There is no network-level configuration. When in doubt, behave per-site.
- **Database access goes through `$wpdb->prepare()`.** Never concatenate user input. The wpdb instance is the global `$wpdb`; in classes, declare it `global` inside the method.
- For HTTP calls to providers, use `wp_remote_get`, `wp_remote_post`, `wp_remote_head`, `wp_remote_request`. The one exception is the sync engine's parallel and chunked uploads, which use `curl_multi` on purpose (that is why `ext-curl` is a requirement); do not add a second one. **Always pass an explicit `timeout`**: 30 seconds for short metadata operations; the provider's transfer timeout (the "Transfer Timeout" setting) for uploads; for downloads that setting or 300 seconds, whichever is longer. Defaulting to no-timeout is a hung-request bug waiting to happen.

### Compatibility

- **PHP 7.4 minimum.** Do NOT use:
  - PHP 8.0+ syntax: named arguments, `match()`, constructor property promotion, nullsafe `?->`, `mixed` return type, throw expressions.
  - PHP 8.1+ syntax: `readonly` properties, native `enum` (this is why `PluginState` is a class with `const`, not an `enum`), first-class callable syntax `f(...)`.
  - PHP 8.2+ syntax: readonly classes, DNF types, `null`/`true`/`false` standalone types.
- **WordPress 5.1 minimum.** Don't use APIs added later than 5.1 without a `function_exists()` guard.
- All `.php` files must start with `if (!defined('ABSPATH')) { exit; }` immediately after the namespace declaration. This is enforced project-wide.

### Logging

- **`Logger` is the only place `error_log()` should be called directly.** That class has a file-wide `phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log`. Anywhere else, calling `error_log()` is a defect — use `Logger::error()`, `Logger::warning()`, `Logger::info()`, or `Logger::debug()`.
- **Nothing is written unless the site asked for it** (Settings tab toggle, `DILUXONE_OFFLOAD_VERBOSE_LOGGING` constant, or `WP_DEBUG`). With logging on, `error` and `warning` are written; `info` and `debug` also need verbose logging. A production install that turned nothing on stays silent — wordpress.org expects that.
- The logger dedupes identical messages within a 5-minute window. This is intentional for hot paths (stream wrapper, cache lookups). Don't try to defeat the dedupe with random suffixes.

### Performance

- **`CloudStreamWrapper` is the hot path.** Every media read/write in the WordPress install goes through it. Avoid:
  - Synchronous network calls inside `stream_read`, `stream_write`, `url_stat` unless a cache miss.
  - Allocations of large strings or arrays per call.
  - `error_log` calls in hot methods (use `Logger::debug` so they're gated).
- **Connection-health checks must be idempotent.** A repeated `decrypt_failed` event must NOT inflate `consecutive_failures` on every page load. The pattern is in `ConfigManager::get_config()`, around its call to `record_connection_failure()` — preserve it.
- For sync, the engine uses parallel/chunked uploads via `prepare_batch_upload_handle()` and `prepare_chunked_upload_handle()` on the provider. Don't introduce sequential per-file loops in new sync paths.

---

## Deliberate style choices

These look odd but are intentional. A review does not flag them:

- **Yoda conditions are NOT used.** This codebase uses `$x === 5`, not `5 === $x`. Don't suggest the swap.
- **`function_name_in_snake_case`** is intentional for free functions and methods (WordPress standard). Don't suggest camelCase.
- **`Class\Names\InPascalCase`** for namespaced classes is correct. Don't suggest snake_case for them.
- The mixed file-naming style (`class-diluxone-offload-*.php` AND `PascalCase.php`) is **deliberate and handled by the autoloader**. Don't suggest renaming files for consistency.
- **Multiple AJAX action prefixes** (`wp_ajax_diluxone_offload_*`) are deliberate — older handlers live in `Admin`, newer ones in `Plugin`. The split is in the middle of an organic refactor; don't suggest re-merging.
- **The `⭐` markers in comments** are intentional: they flag the places where a decision explains *why* something is the way it is. Don't suggest removing them.

---

## What a review looks for

The failure modes that have actually shown up in this codebase, or are likely to:

- **Missing escaping on output.** A new template that does `<?php echo $foo ?>` instead of `<?php echo esc_html($foo) ?>` is a defect.
- **Missing sanitization on input.** A new AJAX handler that reads `$_POST['x']` and stores it without `sanitize_text_field()` (or stricter) is a defect.
- **Missing `check_ajax_referer` or `current_user_can` on a new AJAX handler.** Both are required.
- **Plain SQL strings instead of `$wpdb->prepare()`.** Even for "simple" queries with `intval()`-cast inputs, the convention is `prepare()`.
- **New external HTTP calls without `timeout`** in the args. Always specify, never default. An upload request (a single PUT, a block, a commit, a batch handle) takes the provider's transfer timeout, which is the "Transfer Timeout" setting; a download takes the setting or 300 seconds, whichever is longer; a control request (HEAD, listing, the health probe) keeps a short fixed one.
- **New external HTTP calls without integration with the connection-health system.** Provider classes record failures via `ConfigManager::record_connection_failure()`. New calls that fail silently break the banner and the write-refusal logic.
- **New strings not wrapped in `__()`** — particularly when adding admin UI text or error messages.
- **Hardcoded English strings inside `templates/`.** Templates are the most common place for accidental untranslated strings.
- **Logging credentials.** Any `Logger::*` or `error_log` line that includes a `provider_config`, `access_key`, `api_key`, `password`, decrypted ciphertext, or `print_r($config)` is a defect.
- **Direct `error_log()` calls outside `Logger`.** Defect.
- **PHP 8.0+ syntax slipping in.** Named args, `match`, nullsafe, etc. — flag and reject.
- **New top-level plugin states.** Push back hard unless justified.
- **File handles or stream resources not closed.** In `class-diluxone-offload-sync-manager.php` and the stream wrapper, leaks compound under high upload counts.
- **`$wpdb` queries inside loops.** Suggest batching when you see a `foreach { $wpdb->... }` pattern in a non-trivial loop.

When you're not sure, ask in a review comment instead of guessing, and point at the existing pattern by file and class (e.g. "see `Admin::ajax_test_connection` for the standard nonce + capability + sanitize sequence"). Don't repeat what CI already reports. The maintainer is the final reviewer of every merge: the review surfaces what matters, the merge decision is human.

## Where the other rules live

- Branch names, PR titles, commit messages and what CI enforces: [`CONTRIBUTING.md`](../CONTRIBUTING.md).
- Rules for coding agents: [`AGENTS.md`](../AGENTS.md).
- WordPress-plugin rules shared by every DiluxOne plugin, from the wordpress.org reviews: [`review-profiles/plugin-wp.md`](https://github.com/DiluxOne/.github/blob/main/review-profiles/plugin-wp.md) in `DiluxOne/.github`.
