# Architecture

How DiluxOne Offload is built, the rules its code follows, and what a review of it looks for. This is the single source: people read it, coding agents read it (through [`AGENTS.md`](../AGENTS.md)), and the automated Claude review reads it on every pull request. When the code changes one of these facts, this file changes in the same pull request.

When in doubt, prefer the project's existing patterns over textbook WordPress patterns.

## What this repo is

WordPress plugin that offloads media files to cloud object storage and
serves them back transparently. One provider ships today: **Azure Blob
Storage** (bring your own credentials). The plugin is GPL-2.0-or-later
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
| `includes/interfaces/interface-cloud-storage-client.php` | `CloudStorageClientInterface` — contract for all providers. |
| `includes/factories/class-cloud-storage-factory.php` | `CloudStorageFactory::create($provider, $config)`. |
| `includes/providers/class-azure-provider.php` | `AzureProvider` — Azure Blob Storage REST API. |
| `includes/Enums/class-plugin-state.php` | `Enums\PluginState` (string constants, NOT PHP 8.1 enum — PHP 7.4 minimum). |
| `includes/Enums/SyncStatus.php` | `Enums\SyncStatus`. |
| `includes/DTOs/*.php` | Value objects with `->toArray()` — `PluginConfig`, `AzureConfig`, `ConnectionResult`, `FileInfo`, `OperationResult`, `UploadResult`, `SyncProgress`, etc. |
| `includes/class-diluxone-offload-image-editor-{gd,imagick}.php` | Image-editor adapters that play nicely with the stream wrapper. |
| `templates/admin-*.php` | Admin views (rendered by `Admin`). One file per tab: Overview, Cloud Provider, Sync & Offloading, Settings, Status. |
| `assets/css/admin.css`, `assets/js/admin.js` | Plugin runtime assets bundled with the plugin. |
| `languages/*.{po,mo,pot}` | Text domain `diluxone-offload`. Only the `.pot` ships; the `.po`/`.mo` for `es_AR`, `es_ES`, `es_MX`, `pt_BR`, `pt_PT`, `fr_FR`, `de_DE`, `it_IT` stay in the repo as the source for translate.wordpress.org (see `.distignore`). |
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

Stored in WP option `diluxone_offload_connection_health`. The shape:

```php
[
    'is_healthy'           => bool,
    'error_code'           => string|null, // e.g. 'decrypt_failed', '401', '403', '404', 'exception'
    'error_message'        => string|null,
    'consecutive_failures' => int,
    'last_check'           => int (timestamp),
]
```

Conventions:
- **Failure recording is idempotent for the same error_code.** `ConfigManager::record_failure()` does NOT bump `consecutive_failures` if the previous failure had the same code. This prevents `decrypt_failed` from inflating the counter on every page load.
- **After `consecutive_failures >= 3` the stream wrapper refuses writes** (`CloudStreamWrapper::writes_allowed()`): the upload fails and WordPress reports it. There is **no local fallback** — nothing is ever written to `uploads/` on the server instead. Any proposal to add one is a defect. The threshold is the agreed safety valve; don't change it without discussion.
- **Two helpers map `error_code` to user-facing copy**, both in `Admin`: `pause_reason_short(string $error_code): string` and `health_banner_copy(string $error_code, string $error_message): array`. The same vocabulary appears in the banner, the Status cards, and the Overview cards. If you add a new error_code, update BOTH helpers.

---

## Hard rules

### Security (highest priority)

- **All AJAX endpoints** must call `check_ajax_referer('diluxone_offload_admin', 'nonce')` AND `current_user_can('manage_options')`. The pattern is established and consistent across `Admin::ajax_*` methods. Any new AJAX action without both checks is a defect.
- **All `$_POST` / `$_GET` input** must be sanitized: `sanitize_text_field`, `esc_url_raw`, `sanitize_email`, or `wp_kses` for HTML. Never trust raw `$_POST['x']`.
- **All output** must be escaped: `esc_html`, `esc_attr`, `esc_url`, `esc_textarea`. Never echo a variable into HTML without escaping.
- **All SQL** must use `$wpdb->prepare()`. Concatenating user input into SQL strings is a defect, even when the value "looks safe". `class-diluxone-offload-db.php` is the reference for the correct pattern.
- **Credentials must NEVER appear in logs.** This includes Azure access keys, decrypted plaintext credentials, and anything in the `provider_config` array. The pattern `Logger::error('failed: ' . print_r($config, true))` is forbidden — that array contains the credential. When logging connection failures, log the `error_code` and a sanitized `error_message`, not the full payload. The connection-health system is designed for this purpose; use it.
- **Encryption is AES-256-GCM with a key derived from WP salts** (`wp_salt('auth') . wp_salt('secure_auth')` via HMAC-SHA256). Do not propose downgrading the cipher, removing GCM authentication, switching to CBC, accepting a plaintext fallback, or persisting the key anywhere. The `Crypto::encrypt()` / `Crypto::decrypt()` interface is stable; if a credential cannot be decrypted, `decrypt()` returns `null` and the caller surfaces the failure to the user (`decrypt_failed` connection-health event). There is intentionally no fallback to plaintext storage.
- **The `DILUXONEOFFLOADENC1:` prefix on encrypted values is a versioning hint.** A future key rotation may bump it. Don't strip it, parse it manually, or assume specific positions of bytes after it.

### WordPress conventions

- All user-facing strings must be wrapped in WordPress translation functions (`__()`, `_e()`, `_n()`, `esc_html__()`, `esc_html_e()`, etc.) with the text domain `diluxone-offload`. Translations come from translate.wordpress.org — a hardcoded English string can never be translated.
- `current_user_can('manage_options')` is the standard capability check for admin operations. We don't define custom capabilities (yet).
- Multisite-aware: configuration is per site, always. There is no network-level configuration. When in doubt, behave per-site.
- **Database access goes through `$wpdb->prepare()`.** Never concatenate user input. The wpdb instance is the global `$wpdb`; in classes, declare it `global` inside the method.
- For HTTP calls to providers, use `wp_remote_get`, `wp_remote_post`, `wp_remote_head`, `wp_remote_request`. The one exception is the sync engine's parallel and chunked uploads, which use `curl_multi` on purpose (that is why `ext-curl` is a requirement); do not add a second one. **Always pass an explicit `timeout`**: 30 seconds for short metadata operations, 300 seconds for file transfers. Defaulting to no-timeout is a hung-request bug waiting to happen.

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
- **Connection-health checks must be idempotent.** A repeated `decrypt_failed` event must NOT inflate `consecutive_failures` on every page load. The pattern is in `ConfigManager::record_failure()` — preserve it.
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
- **New external HTTP calls without `timeout`** in the args. Always specify, never default.
- **New external HTTP calls without integration with the connection-health system.** Provider classes record failures via `ConfigManager::record_failure()`. New calls that fail silently break the banner and the write-refusal logic.
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
