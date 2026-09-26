=== DiluxOne Offload – Media Storage ===
Contributors: pablodiloreto
Tags: media, offload, azure, cloud storage, uploads
Requires at least: 5.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Move your media to cloud object storage and serve it from there. Replaces /uploads/ transparently, with no URL rewriting.

== Description ==

DiluxOne Offload moves your WordPress media library to Azure Blob Storage and serves files directly from the cloud — without breaking the Media Library UI, plugins, or existing content.

The plugin uses a custom PHP stream wrapper to intercept every read and write to `/wp-content/uploads/`, so WordPress, WooCommerce, page builders, image editors, and any plugin that calls standard filesystem functions (`fopen`, `file_get_contents`, `unlink`, etc.) keep working unchanged.

= Key features =

* **Azure Blob Storage** — bring your own storage account; nothing is shared with anyone else.
* **Transparent stream wrapper** — no URL rewriting, no regex on post content, no database migration required for URLs.
* **Sync with resumable state machine** — start, cancel, resume after an interruption, retry failed files, resync from scratch.
* **Offloading mode** — after a successful sync you can delete the local copies to free disk space; the stream wrapper keeps everything working.
* **Connection health monitoring** — when the cloud is unreachable, new uploads are refused with a clear error instead of landing somewhere else, and a banner on the plugin's admin pages says why until it recovers.
* **No plugin data on disk** — no cache, log or data files anywhere on the server. The one time the plugin writes to the uploads directory is when you disconnect, to copy your own media back to where WordPress expects it.
* **Large files don't need large memory** — a download goes straight to disk, and an upload is sent in 4 MiB blocks, so PHP never holds more than one block of a file at a time and a video does not trip `memory_limit`.
* **Multisite aware** — network activation supported; each site keeps its own configuration and file tracking, and its objects live under their own prefix in the container (`uploads/` for the main site, `uploads/sites/<id>/` for the others, the same layout WordPress uses on disk), so several sites can share one container without ever sharing a key.
* **Quiet by default** — with `WP_DEBUG` off and the Settings toggle off the plugin writes nothing to the PHP error log, at any level. `WP_DEBUG` turns on errors and warnings; the toggle adds the informational lines.

= Why a stream wrapper instead of URL rewriting =

Most offload plugins rewrite media URLs in post content, which breaks when you switch providers, move domains, or restore from a backup. DiluxOne Offload leaves URLs alone and rewrites reads/writes at the filesystem layer, so your content stays portable.

= Known limitations =

* One provider: Azure Blob Storage. The container must allow anonymous read of blobs (public access level *Blob*); a private container is refused.
* The initial sync runs in your browser tab and stops if you close it; it resumes where it left off. Nothing runs in the background or via cron.
* Files above the **Maximum File Size** setting (20 MB by default, up to 500 MB) are skipped by the initial sync.
* While the cloud is unreachable, new uploads fail. There is deliberately no local fallback.
* No CDN or custom-domain option: media is served from your storage account's URL.
* **Disconnect from Cloud** needs a writable uploads directory and enough disk for your media.

== External services ==

This plugin connects to Azure Blob Storage, a third-party cloud storage service, to store and serve your media files. **Nothing is sent anywhere until you configure it yourself** in the *Cloud Provider* tab, with an account and credentials you supply. The plugin contacts no other service: it sends no telemetry, no usage data and no licence check to the author or to anyone else.

= Azure Blob Storage =

What it is used for: storing your WordPress media files outside your server and serving them from the cloud.

Where requests go: the Azure Blob REST API at blob.core.windows.net — specifically your own account's host, `https://ACCOUNT.blob.core.windows.net`, where ACCOUNT is the storage account name you enter in the settings.

What data is sent: your media files themselves, together with their relative path, size and MIME type. The storage account key you entered never leaves your server — it is used locally to compute the signature that authenticates each request. No personal data about your visitors or your site's users is sent, and nothing at all about your site reaches the plugin's author.

When requests happen:

* During the initial sync — uploading existing files from `/wp-content/uploads/` to your container.
* On every new media upload — writing the file to the cloud through the stream wrapper.
* On read or delete — when WordPress, or any plugin using filesystem APIs against `/uploads/`, reads or deletes a file. This is also the only front-end traffic: a form or a review that uploads a file goes through the same stream wrapper.
* When the plugin lists your container — for the Overview statistics (cached for five minutes) and for the scan that precedes **Disconnect from Cloud**.
* A connection-health check — a small GET for your container's properties — when you open one of the plugin's admin pages, at most once every 5 minutes, and again from a write that follows three consecutive failures, so uploads resume on their own when the cloud is back. Nothing runs via cron.
* During **Disconnect from Cloud** — downloading your files back to the server.

This is **your own Azure account**, under your own agreement with Microsoft. Neither DiluxOne nor the plugin's author is a party to it and neither has any access to your data. Your use of the service is subject to Microsoft's terms:

* Service: [Azure Blob Storage](https://azure.microsoft.com/services/storage/blobs/) (https://azure.microsoft.com/services/storage/blobs/)
* Terms of Service: [Microsoft Online Services Terms](https://www.microsoft.com/licensing/terms/productoffering/MicrosoftAzure) (https://www.microsoft.com/licensing/terms/productoffering/MicrosoftAzure)
* Privacy Policy: [Microsoft Privacy Statement](https://www.microsoft.com/privacy/privacystatement) (https://www.microsoft.com/privacy/privacystatement)

== Installation ==

1. Upload the `diluxone-offload` folder to `/wp-content/plugins/`, or install via the WordPress Plugins screen.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Open the new **DiluxOne Offload** menu in the admin sidebar.
4. Go to **Cloud Provider**, select Microsoft Azure Blob Storage, enter the storage account, container and access key, and click **Test Connection**.
5. Save the configuration.
6. Go to **Sync & Offloading**, run the initial sync, and enable offloading when sync is complete.

= Requirements =

* WordPress 5.1 or higher.
* PHP 7.4 or higher.
* `ext-curl` and `ext-openssl` enabled.
* A writable uploads directory only for **Disconnect from Cloud**, when your media is copied back. Transfers use the PHP temporary directory for their scratch files, never `uploads/`.
* An Azure Blob Storage account, a container whose public access level is **Blob** (anonymous read access for blobs, so browsers can load your media straight from it), and the account's access key. **Test Connection** refuses a private container and says so.

== Frequently Asked Questions ==

= Does this plugin modify my existing media URLs in the database? =

No. The stream wrapper intercepts filesystem calls transparently — your post content, the `wp_posts` table, and the `wp_postmeta` table are never rewritten.

= What happens if the cloud is temporarily unreachable? =

The plugin monitors connection health. While the cloud is unreachable, a new upload fails with WordPress's own "could not be moved" error and nothing is saved anywhere, so you never end up with a file that looks uploaded but isn't. A banner on the plugin's admin pages explains the failure. Files already in the cloud keep being served from the storage account URL. The plugin re-checks the connection at most every five minutes and uploads resume on their own once it is back.

= Does the plugin write any files to my server? =

Not for itself: it has no cache, log or data files on disk; everything it needs lives in the WordPress options table and its own database table. Your media is written by WordPress core through the plugin's stream wrapper to the cloud, passing through a temporary file in the PHP temp directory that is deleted right after the upload.

The one operation that writes to the server is **Sync & Offloading → Disconnect from Cloud**. It copies your media back from the container to the exact uploads-directory paths WordPress has on record (resolved at runtime with `wp_upload_dir()`), so the Media Library works again without the plugin. It restores only what sits under the `uploads/` prefix of your own container, never a script or executable file name (PHP, JavaScript, HTML, shell or Windows executables) whatever put it there, and it runs only when you click it.

= Can I move to another storage account or container later? =

Yes. Remove the current provider configuration from the admin, enter the new account and container, run a full resync, and the plugin starts serving from the new location. No URL rewriting required.

= Will this work with WooCommerce / Elementor / image editors? =

Yes. Because the stream wrapper operates at the filesystem layer, any plugin that reads or writes files under `/uploads/` using standard PHP functions works unchanged.

= Does the plugin delete my local files automatically? =

Only if you explicitly opt in. After a successful sync you can click **Delete Local Files** in the Sync & Offloading tab. Until you do that, files are kept in both locations. A file that is empty (0 bytes) when the sync scans it is skipped: it is neither uploaded nor tracked, so **Delete Local Files** leaves it alone.

= If I delete a file from the Media Library, is it deleted from the cloud too? =

Yes, while offloading is active: the stream wrapper turns the deletion into a delete on your container, thumbnails included. If you have synced but not yet enabled offloading, WordPress deletes only the local copy; the copy already in your container is not removed automatically.

= What happens when I uninstall the plugin? =

Deleting the plugin from the Plugins screen removes everything it created in your database: its options (all prefixed `diluxone_offload_`), its transients and its file-tracking table (`diluxone_offload_files`, with your table prefix) — on every site of a network. Deactivating alone keeps all of that, so you can deactivate and reactivate without losing your configuration.

Your media files are never touched by uninstalling: whatever is in `/wp-content/uploads/` stays there, and whatever is in your container stays in your container. If offloading was active and local copies had been deleted, download them first with **Sync & Offloading → Disconnect from Cloud**, otherwise WordPress will be pointing at files that are no longer on the server.

= How do I enable verbose debug logging? =

Go to **DiluxOne Offload → Settings → Enable detailed debug logging**. Logs are written to the standard PHP `error_log` destination. Disable it in production unless you are actively troubleshooting — it may impact performance.

With that setting off and `WP_DEBUG` off, the plugin writes nothing to the PHP error log at all, at any level. With `WP_DEBUG` on it writes errors and warnings; the setting adds the rest.

= Is the plugin multisite compatible? =

Yes. It can be network-activated; each site then has its own Cloud Provider configuration and its own file-tracking table, so different sites can use different containers or accounts — or share one: a site's objects are stored under `uploads/sites/<id>/` (the main site under `uploads/`), and each site only ever lists, syncs and restores its own prefix.

= How are my Azure credentials stored? =

The Azure access key is encrypted with AES-256-GCM before it is written to the WordPress options table. The encryption key is derived from your site's WordPress salts (`AUTH_KEY` / `SECURE_AUTH_KEY` and the corresponding salts in `wp-config.php`), so as long as those salts are defined in `wp-config.php` (as WordPress recommends) a database dump on its own is not enough to recover the credentials — the attacker also needs filesystem access to `wp-config.php`.

If you ever rotate the WordPress salts, the existing encrypted credentials become unreadable; the plugin will surface the provider as "not configured" and you simply re-enter the credentials in the *Cloud Provider* tab. There is intentionally no plaintext fallback.

Requirements: PHP `ext-openssl` (enabled by default on virtually every host).

== Screenshots ==

1. Overview: provider configured, media synced, offloading active.
2. Cloud Provider: choosing Azure Blob Storage.
3. Cloud Provider: entering the storage account, key and container, and testing the connection.
4. Provider saved and ready to sync.
5. The first sync, file by file, in the browser.
6. Sync complete: enable offloading now or later.
7. Offloading active: disconnect from the cloud, or delete the local copies.
8. Settings: file-size limit, transfer timeout, HTTPS for cloud URLs, debug logging.
9. Status: plugin state, environment and provider at a glance.

== Changelog ==

= 2.0.0 =
Unreleased.

* The "Upload Timeout" setting is now "Transfer Timeout" and governs every upload request: the single upload, each block and its commit, and the sync's parallel transfers. Before, block commits waited a fixed 300 seconds whatever the setting said. Downloads keep waiting at least the 300 seconds they always had; a higher setting raises that too.
* A transfer that runs past the timeout is reported as such: the connection-health banner says "Cloud Transfer Timed Out" and links to Settings, instead of showing a made-up error code.
* With debug logging on, every successful upload through the stream wrapper writes one line (path and size).
* The suites now prove what each setting does, not only that it is saved.

= 1.0.0 =
First public release.

* Azure Blob Storage provider with your own account and key; media served from `https://<account>.blob.core.windows.net`.
* Transparent PHP stream wrapper on `/wp-content/uploads/`: no URL rewriting, no database migration.
* Sync with cancel, resume and retry; optional deletion of the local copies once synced; **Disconnect from Cloud** brings everything back.
* Connection health: when the cloud is unreachable an upload fails with a clear error and nothing is written elsewhere.
* Streaming transfers: downloads go straight to disk and uploads go in 4 MiB blocks, so large files do not need large memory.
* "Force HTTPS for cloud storage URLs" option; multisite with per-site configuration; credentials encrypted at rest (AES-256-GCM, key derived from the site's salts); quiet unless `WP_DEBUG` or the debug toggle is on.
* Uninstalling removes the plugin's options, transients and table; media files are never touched.

== Upgrade Notice ==

= 1.0.0 =
First public release.
