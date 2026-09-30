=== DiluxOne Offload – Media Storage ===
Contributors: pablodiloreto
Tags: s3, offload, azure, cloud storage, uploads
Requires at least: 5.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Move your media to cloud object storage and serve it from there. Replaces /uploads/ transparently, with no URL rewriting.

== Description ==

DiluxOne Offload moves your WordPress media library to Azure Blob Storage or to any S3-compatible service (Amazon S3, Cloudflare R2, Backblaze B2, DigitalOcean Spaces, Wasabi, Google Cloud Storage, Hetzner, Akamai (Linode), Vultr, Scaleway, OVHcloud, IDrive e2, MinIO) and serves files directly from the cloud — without breaking the Media Library UI, plugins, or existing content.

The plugin uses a custom PHP stream wrapper to intercept every read and write to `/wp-content/uploads/`, so WordPress, WooCommerce, page builders, image editors, and any plugin that calls standard filesystem functions (`fopen`, `file_get_contents`, `unlink`, etc.) keep working unchanged.

= Key features =

* **Azure Blob Storage or S3-compatible storage** — bring your own storage account or bucket; nothing is shared with anyone else. For S3-compatible services, pick the service and the plugin fills in the endpoint and the public URL; a CDN or a custom domain goes in the Public URL field.
* **Transparent stream wrapper** — no URL rewriting, no regex on post content, no database migration required for URLs.
* **Sync with resumable state machine** — start, cancel, resume after an interruption, retry failed files, resync from scratch.
* **Offloading mode** — after a successful sync you can delete the local copies to free disk space; the stream wrapper keeps everything working.
* **Connection health monitoring** — when the cloud is unreachable, new uploads are refused with a clear error instead of landing somewhere else, and a banner on the plugin's admin pages says why until it recovers.
* **No plugin data on disk** — no cache, log or data files anywhere on the server. The one time the plugin writes to the uploads directory is when you disconnect, to copy your own media back to where WordPress expects it.
* **Large files don't need large memory** — a download goes straight to disk, and an upload is sent in blocks of at most 5 MiB (4 MiB on Azure, 5 MiB parts on S3-compatible services), so PHP never holds more than one block of a file at a time and a video does not trip `memory_limit`.
* **Multisite aware** — network activation supported; each site keeps its own configuration and file tracking, and its objects live under their own prefix in the container or bucket (`uploads/` for the main site, `uploads/sites/<id>/` for the others, the same layout WordPress uses on disk), so several sites can share one container or bucket without ever sharing a key.
* **Quiet by default** — with `WP_DEBUG` off and the Settings toggle off the plugin writes nothing to the PHP error log, at any level. `WP_DEBUG` turns on errors and warnings; the toggle adds the informational lines.

= Why a stream wrapper instead of URL rewriting =

Most offload plugins rewrite media URLs in post content, which breaks when you switch providers, move domains, or restore from a backup. DiluxOne Offload leaves URLs alone and rewrites reads/writes at the filesystem layer, so your content stays portable.

= Known limitations =

* The media must be publicly readable where it is stored: on Azure, a container whose public access level is *Blob*; on S3-compatible services, a bucket that lets anyone read its objects. **Test Connection** checks it and refuses a private one.
* The initial sync runs in your browser tab and stops if you close it; it resumes where it left off. Nothing runs in the background or via cron.
* Files above the **Maximum File Size** setting (20 MB by default, up to 500 MB) are skipped by the initial sync.
* While the cloud is unreachable, new uploads fail. There is deliberately no local fallback.
* On Azure, media is served from the storage account's URL; on S3-compatible services the Public URL field is where a CDN or custom domain goes.
* **Disconnect from Cloud** needs a writable uploads directory and enough disk for your media.

== External services ==

This plugin connects to one cloud storage service, the one you choose: Azure Blob Storage, or an S3-compatible service (Amazon S3, Cloudflare R2, Backblaze B2, DigitalOcean Spaces, Wasabi, Google Cloud Storage, Hetzner Object Storage, Akamai (Linode) Object Storage, Vultr Object Storage, Scaleway Object Storage, OVHcloud Object Storage, IDrive e2, or a server whose address you type). It uses it to store and serve your media files. **Nothing is sent anywhere until you configure it yourself** in the *Cloud Provider* tab, with an account and credentials you supply. The plugin contacts no other service: it sends no telemetry, no usage data and no licence check to the author or to anyone else.

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
* Terms of Service: [Microsoft Azure Legal Information](https://azure.microsoft.com/support/legal/) (https://azure.microsoft.com/support/legal/), which links the Microsoft Products and Services Agreement and the Online Services Terms
* Privacy Policy: [Microsoft Privacy Statement](https://www.microsoft.com/privacy/privacystatement) (https://www.microsoft.com/privacy/privacystatement)

= S3-compatible storage =

What it is used for: the same as above, on the S3-compatible service you choose.

Where requests go: the endpoint of the service you chose, which the form fills in for each service and you can change: Amazon S3 at `https://s3.REGION.amazonaws.com` (requests address `https://BUCKET.s3.REGION.amazonaws.com`), Cloudflare R2 at `https://ACCOUNT_ID.r2.cloudflarestorage.com`, Backblaze B2 at `https://s3.REGION.backblazeb2.com`, DigitalOcean Spaces at `https://REGION.digitaloceanspaces.com`, Wasabi at `https://s3.REGION.wasabisys.com`, Google Cloud Storage at `https://storage.googleapis.com`, Hetzner Object Storage at `https://REGION.your-objectstorage.com`, Akamai (Linode) Object Storage at `https://REGION.linodeobjects.com`, Vultr Object Storage at `https://REGION.vultrobjects.com`, Scaleway Object Storage at `https://s3.REGION.scw.cloud`, OVHcloud Object Storage at `https://s3.REGION.io.cloud.ovh.net`, IDrive e2 at `https://s3.REGION.idrivee2.com` (or the endpoint of your own its dashboard shows), or the address you type under Custom. Browsers load your media from the Public URL you set, which can be the service's own address, a CDN or a custom domain.

What data is sent: your media files themselves, together with their relative path (the object key), size and MIME type, and a signature of each request computed from your secret access key. The secret itself never leaves your server. No personal data about your visitors or your site's users is sent, and nothing at all about your site reaches the plugin's author.

When requests happen: the same moments as for Azure above. The connection check is different: when you press **Test Connection**, and at most every five minutes while you browse the plugin's admin pages, the plugin writes a 32-byte probe object under your site's prefix, reads it back from the Public URL without credentials (to prove browsers can load your media) and deletes it.

This is **your own account** with that service, under your own agreement with its provider. Neither DiluxOne nor the plugin's author is a party to it and neither has any access to your data. Your use of the service is subject to its provider's terms:

Amazon S3:

* Service: [Amazon S3](https://aws.amazon.com/s3/) (https://aws.amazon.com/s3/)
* Terms of Service: [AWS Service Terms](https://aws.amazon.com/service-terms/) (https://aws.amazon.com/service-terms/)
* Privacy Policy: [AWS Privacy Notice](https://aws.amazon.com/privacy/) (https://aws.amazon.com/privacy/)

Cloudflare R2:

* Service: [Cloudflare R2](https://www.cloudflare.com/products/r2/) (https://www.cloudflare.com/products/r2/)
* Terms of Service: [Cloudflare Terms](https://www.cloudflare.com/terms/) (https://www.cloudflare.com/terms/)
* Privacy Policy: [Cloudflare Privacy Policy](https://www.cloudflare.com/privacypolicy/) (https://www.cloudflare.com/privacypolicy/)

Backblaze B2:

* Service: [Backblaze B2](https://www.backblaze.com/cloud-storage) (https://www.backblaze.com/cloud-storage)
* Terms of Service: [Backblaze Terms of Service](https://www.backblaze.com/company/policy/terms-of-service) (https://www.backblaze.com/company/policy/terms-of-service)
* Privacy Policy: [Backblaze Privacy Notice](https://www.backblaze.com/company/policy/privacy) (https://www.backblaze.com/company/policy/privacy)

DigitalOcean Spaces:

* Service: [DigitalOcean Spaces](https://www.digitalocean.com/products/spaces) (https://www.digitalocean.com/products/spaces)
* Terms of Service: [DigitalOcean Terms of Service](https://www.digitalocean.com/legal/terms-of-service-agreement) (https://www.digitalocean.com/legal/terms-of-service-agreement)
* Privacy Policy: [DigitalOcean Privacy Policy](https://www.digitalocean.com/legal/privacy-policy) (https://www.digitalocean.com/legal/privacy-policy)

Wasabi:

* Service: [Wasabi](https://wasabi.com/cloud-object-storage) (https://wasabi.com/cloud-object-storage)
* Terms of Service: [Wasabi Terms of Use](https://wasabi.com/legal/terms-of-use) (https://wasabi.com/legal/terms-of-use)
* Privacy Policy: [Wasabi Privacy Policy](https://wasabi.com/legal/privacy-policy) (https://wasabi.com/legal/privacy-policy)

Google Cloud Storage:

* Service: [Google Cloud Storage](https://cloud.google.com/storage) (https://cloud.google.com/storage)
* Terms of Service: [Google Cloud Terms of Service](https://cloud.google.com/terms) (https://cloud.google.com/terms)
* Privacy Policy: [Google Privacy Policy](https://policies.google.com/privacy) (https://policies.google.com/privacy)

Hetzner Object Storage:

* Service: [Hetzner Object Storage](https://www.hetzner.com/storage/object-storage/) (https://www.hetzner.com/storage/object-storage/)
* Terms of Service: [Hetzner Terms and Conditions](https://www.hetzner.com/legal/terms-and-conditions/) (https://www.hetzner.com/legal/terms-and-conditions/)
* Privacy Policy: [Hetzner Privacy Policy](https://www.hetzner.com/legal/privacy-policy/) (https://www.hetzner.com/legal/privacy-policy/)

Akamai (Linode) Object Storage:

* Service: [Akamai Object Storage](https://www.linode.com/products/object-storage/) (https://www.linode.com/products/object-storage/)
* Terms of Service: [Akamai Cloud Master Service Agreement](https://www.linode.com/legal/msa/) (https://www.linode.com/legal/msa/)
* Privacy Policy: [Akamai Privacy Statement](https://www.akamai.com/legal/privacy-statement) (https://www.akamai.com/legal/privacy-statement)

Vultr Object Storage:

* Service: [Vultr Object Storage](https://www.vultr.com/products/object-storage/) (https://www.vultr.com/products/object-storage/)
* Terms of Service: [Vultr Terms of Service](https://www.vultr.com/legal/tos/) (https://www.vultr.com/legal/tos/)
* Privacy Policy: [Vultr Privacy Policy](https://www.vultr.com/legal/privacy/) (https://www.vultr.com/legal/privacy/)

Scaleway Object Storage:

* Service: [Scaleway Object Storage](https://www.scaleway.com/en/object-storage/) (https://www.scaleway.com/en/object-storage/)
* Terms of Service: [Scaleway Contracts](https://www.scaleway.com/en/contracts/) (https://www.scaleway.com/en/contracts/)
* Privacy Policy: [Scaleway Privacy Policy](https://www.scaleway.com/en/privacy-policy/) (https://www.scaleway.com/en/privacy-policy/)

OVHcloud Object Storage:

* Service: [OVHcloud Object Storage](https://www.ovhcloud.com/en/public-cloud/object-storage/) (https://www.ovhcloud.com/en/public-cloud/object-storage/)
* Terms of Service: [OVHcloud Contracts](https://www.ovhcloud.com/en/terms-and-conditions/contracts/) (https://www.ovhcloud.com/en/terms-and-conditions/contracts/)
* Privacy Policy: [OVHcloud Privacy Policy](https://www.ovhcloud.com/en/terms-and-conditions/privacy-policy/) (https://www.ovhcloud.com/en/terms-and-conditions/privacy-policy/)

IDrive e2:

* Service: [IDrive e2](https://www.idrive.com/s3-storage-e2/) (https://www.idrive.com/s3-storage-e2/)
* Terms of Service: [IDrive e2 Terms](https://www.idrive.com/s3-storage-e2/terms) (https://www.idrive.com/s3-storage-e2/terms)
* Privacy Policy: [IDrive Privacy Policy](https://www.idrive.com/privacy) (https://www.idrive.com/privacy)

Custom endpoint: the plugin talks only to the server whose address you typed (MinIO, Ceph or any other that speaks the S3 API). No third party is involved unless that server belongs to one you chose.

== Installation ==

1. Upload the `diluxone-offload` folder to `/wp-content/plugins/`, or install via the WordPress Plugins screen.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Open the new **DiluxOne Offload** menu in the admin sidebar.
4. Go to **Cloud Provider**, select Microsoft Azure Blob Storage (enter the storage account, container and access key) or S3-compatible storage (pick the service, then enter the region, bucket, keys and, if it is not filled in, the public URL), and click **Test Connection**.
5. Save the configuration.
6. Go to **Sync & Offloading**, run the initial sync, and enable offloading when sync is complete.

= Requirements =

* WordPress 5.1 or higher.
* PHP 7.4 or higher.
* `ext-curl` and `ext-openssl` enabled.
* A writable uploads directory only for **Disconnect from Cloud**, when your media is copied back. Transfers use the PHP temporary directory for their scratch files, never `uploads/`.
* An Azure Blob Storage account, a container whose public access level is **Blob** (anonymous read access for blobs, so browsers can load your media straight from it), and the account's access key; or a bucket on an S3-compatible service that lets anyone read its objects, and an access key pair allowed to put, get, delete and list objects in it. **Test Connection** refuses a private container or bucket and says so.

== Frequently Asked Questions ==

= Does this plugin modify my existing media URLs in the database? =

No. The stream wrapper intercepts filesystem calls transparently — your post content, the `wp_posts` table, and the `wp_postmeta` table are never rewritten.

= What happens if the cloud is temporarily unreachable? =

The plugin monitors connection health. While the cloud is unreachable, a new upload fails with WordPress's own "could not be moved" error and nothing is saved anywhere, so you never end up with a file that looks uploaded but isn't. A banner on the plugin's admin pages explains the failure. Files already in the cloud keep being served from the storage account URL. The plugin re-checks the connection at most every five minutes and uploads resume on their own once it is back.

= Does the plugin write any files to my server? =

Not for itself: it has no cache, log or data files on disk; everything it needs lives in the WordPress options table and its own database table. Your media is written by WordPress core through the plugin's stream wrapper to the cloud, passing through a temporary file in the PHP temp directory that is deleted right after the upload.

The one operation that writes to the server is **Sync & Offloading → Disconnect from Cloud**. It copies your media back from the container to the exact uploads-directory paths WordPress has on record (resolved at runtime with `wp_upload_dir()`), so the Media Library works again without the plugin. It restores only what sits under the `uploads/` prefix of your own container, never a script or executable file name (PHP, JavaScript, HTML, shell or Windows executables) whatever put it there, and it runs only when you click it.

= What if the container or bucket already holds files? =

Before the first sync, Sync & Offloading › Sync looks under this site's folder in it (`uploads/`, or `uploads/sites/<id>/` for a site of a network). If something is there, from an earlier install or a staging copy, it says how many files and how much, and lets you continue with them, empty that folder (you type the container's or bucket's name to confirm) or connect another one. Emptying deletes only this site's folder, never anything else in the container or bucket, and only before the first sync.

= Can I move to another storage account or bucket later? =

Yes, including from Azure to an S3-compatible service or back. Remove the current provider configuration from the admin, enter the new account, container or bucket, run a full resync, and the plugin starts serving from the new location. No URL rewriting required.

= Will this work with WooCommerce / Elementor / image editors? =

Yes. Because the stream wrapper operates at the filesystem layer, any plugin that reads or writes files under `/uploads/` using standard PHP functions works unchanged.

= Does the plugin delete my local files automatically? =

Only if you explicitly opt in. After a successful sync you can click **Delete Local Files** in Sync & Offloading › Offloading. Until you do that, files are kept in both locations. A file that is empty (0 bytes) when the sync scans it is skipped: it is neither uploaded nor tracked, so **Delete Local Files** leaves it alone.

= If I delete a file from the Media Library, is it deleted from the cloud too? =

Yes, while offloading is active: the stream wrapper turns the deletion into a delete on your container, thumbnails included. If you have synced but not yet enabled offloading, WordPress deletes only the local copy; the copy already in your container is not removed automatically.

= What happens when I uninstall the plugin? =

Deleting the plugin from the Plugins screen removes everything it created in your database: its options (all prefixed `diluxone_offload_`), its transients and its file-tracking table (`diluxone_offload_files`, with your table prefix) — on every site of a network. Deactivating alone keeps all of that, so you can deactivate and reactivate without losing your configuration.

Your media files are never touched by uninstalling: whatever is in `/wp-content/uploads/` stays there, and whatever is in your container stays in your container. If offloading was active and local copies had been deleted, download them first with **Sync & Offloading → Disconnect from Cloud**, otherwise WordPress will be pointing at files that are no longer on the server.

= How do I enable verbose debug logging? =

Go to **DiluxOne Offload → Settings → Enable detailed debug logging**. Logs are written to the standard PHP `error_log` destination. Disable it in production unless you are actively troubleshooting — it may impact performance.

With that setting off and `WP_DEBUG` off, the plugin writes nothing to the PHP error log at all, at any level. With `WP_DEBUG` on it writes errors and warnings; the setting adds the rest.

= Is the plugin multisite compatible? =

Yes. It can be network-activated; each site then has its own Cloud Provider configuration and its own file-tracking table, so different sites can use different providers, containers or buckets — or share one: a site's objects are stored under `uploads/sites/<id>/` (the main site under `uploads/`), and each site only ever lists, syncs and restores its own prefix.

= How are my credentials stored? =

The Azure access key and the S3 secret access key are encrypted with AES-256-GCM before they are written to the WordPress options table (the S3 access key ID, like a user name, is not a secret and is stored as it is). The encryption key is derived from your site's WordPress salts (`AUTH_KEY` / `SECURE_AUTH_KEY` and the corresponding salts in `wp-config.php`), so as long as those salts are defined in `wp-config.php` (as WordPress recommends) a database dump on its own is not enough to recover the credentials — the attacker also needs filesystem access to `wp-config.php`.

If you ever rotate the WordPress salts, the existing encrypted credentials become unreadable; the plugin will surface the provider as "not configured" and you simply re-enter the credentials in the *Cloud Provider* tab. There is intentionally no plaintext fallback.

Requirements: PHP `ext-openssl` (enabled by default on virtually every host).

== Screenshots ==

1. Overview: provider configured, media synced, offloading active.
2. Cloud Provider › Connection: choosing Azure Blob Storage.
3. Cloud Provider › Connection: entering the storage account, key and container, and testing the connection.
4. Provider saved and ready to sync.
5. Sync & Offloading › Sync: the first sync, file by file, in the browser.
6. Sync complete: enable offloading now or later.
7. Sync & Offloading › Offloading: offloading active, with the local copies to delete.
8. Settings › Transfers: file-size limit and transfer timeout.
9. Status › Health: plugin state and connection health at a glance.
10. Cloud Provider › Connection: S3-compatible storage, with the service filling in the endpoint and the public URL.

== Changelog ==

= 2.1.0 =
Unreleased.

* Before the first sync, Sync & Offloading › Sync checks this site's folder in the container or bucket. If something is already there (an earlier install, a staging copy) it says how many files and how much, and lets you continue with them, empty that folder (typing the container's or bucket's name) or connect another one. Emptying never touches anything outside this site's folder.
* A sync whose uploads go through ends a pause the connection health had recorded (a key that failed and was fixed since), instead of showing "Paused" for up to five more minutes.
* An upload, a delete or a check that meets a temporary error from the storage service (a 500 or 503, a dropped connection) is tried up to three times in all, instead of failing at once. Backblaze B2 answers that way to about one upload in a hundred, which could leave an image without one of its thumbnails.
* The initial sync uses every parallel upload from the start: a library that begins with large files used to send them one at a time, and now sends them alongside small ones, each upload starting as soon as another finishes.
* A large file's parts (above 10 MB) now go up in parallel, like the other files, instead of one after another while the rest of the sync waited. A part that meets a temporary error from the storage service is sent again instead of failing the file. A very large file no longer has to go up within one request of the sync: the next one takes it up where it was left, sending only the parts the storage service does not have yet.
* The sync and Disconnect from Cloud reuse their connections to the storage service from one group of files to the next, instead of opening a new one, with its secure handshake, for every file.
* Status › Health names the storage service in use (Cloudflare R2, Amazon S3, …) and no longer shows a card that repeated the plugin's state. Screens that speak of the storage say "container or bucket", and a hostname too long for its card (Cloudflare R2's public one) is shortened, with the whole name on hover.
* The Sync, Offloading and Disconnect screens and their windows are built on WordPress' own buttons, notices and cards, and take the accent of your admin colour scheme. Offloading's bar shows, in one full bar, the files only in the cloud and, striped, the ones that still have a copy on this server (the disk Delete Local Files can free). While a sync has files pending, the ones that already failed show as a red part of its bar. Messages these screens showed in English only are now translated.
* Six more services in the S3-compatible provider's Service list: Hetzner Object Storage, Akamai (Linode) Object Storage, Vultr Object Storage, Scaleway Object Storage, OVHcloud Object Storage and IDrive e2. Each fills in the endpoint and, where the service has one, the public URL, and says where its keys come from and how to make the bucket readable.
* A file too large for the storage service's limit on parts goes up in larger parts instead of failing: past about 5 GB on Scaleway (1,000 parts), past about 48 GB elsewhere.

= 2.0.0 =

* S3-compatible storage: besides Azure Blob Storage, the media can live on Amazon S3, Cloudflare R2, Backblaze B2, DigitalOcean Spaces, Wasabi, Google Cloud Storage (HMAC keys) or any server that speaks the S3 API, such as MinIO. Pick the service and the plugin fills in the endpoint and the public URL; the Public URL field is also where a CDN or a custom domain goes. Test Connection proves the keys can write to the bucket and that browsers can read what is stored, and refuses a private bucket. Large files go up in 5 MiB parts; the secret access key is encrypted like the Azure key. "Force HTTPS for cloud storage URLs" leaves alone a Public URL that is plain http on purpose, such as a server on a private network.
* Only a configuration that passed Test Connection can be saved, now checked on the server too: a key other than the tested one is refused.
* The admin is one menu with a screen per submenu (Overview, Cloud Provider, Sync & Offloading, Settings, Status) and tabs only where a screen needs a second level: Connection and Credentials; Sync, Offloading and Disconnect; Transfers, Serving and Logging; Health and System. Every screen is headed "DiluxOne Offload | Screen", shows the site's state at a glance in a column beside the content, and the open tab follows your admin colour scheme. Old links to the tabs keep working.
* Status › Health shows the connection health the plugin already recorded (status, last check, last success, consecutive failures) and how many files the tracking table knows, with a "Check now" button that asks the provider right away; Status › System shows the free disk.
* The screens say what is where: Sync shows how many files are synced, how many still have a local copy, how many live in the cloud only, the last upload made through the site and what the last scan left out (and why); Offloading shows where the media is served from, where the bytes are and since when; Disconnect shows what it would bring back and whether the disk has room; Cloud Provider shows since when it is connected.
* Files uploaded through the site while offloading is on are now counted like the ones the initial sync moved, and deleting the local copies no longer empties the plugin's tracking table, so those figures stay right on the sites that use offloading. A very long list of failed files shows its first 50 rows and says how many there are.
* The Overview shows three state cards (the fourth repeated them); Cloud Provider › Connection offers "Rotate the key" and "Delete Cloud Provider" as buttons; every button with an icon has the icon and its label on one line, and the sync window's buttons read at their size.
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
