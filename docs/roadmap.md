# Roadmap

What DiluxOne Offload does, what is paid, what comes next and what it will not do. Kept current by the maintainer; the issue triage and the code review read it, so a report about something listed here gets the right answer without a human.

## What it does today (free, in the plugin)

- Moves the media library to **Azure Blob Storage** or to **S3-compatible storage** (Amazon S3, Cloudflare R2, Backblaze B2, DigitalOcean Spaces, Wasabi, Google Cloud Storage with HMAC keys, Hetzner, Akamai (Linode), Vultr, Scaleway, OVHcloud, IDrive e2, MinIO and any other server that speaks the S3 API) and serves it from there, through a PHP stream wrapper on `/wp-content/uploads/`: no URL rewriting, no database migration.
- Sync with cancel, resume and retry; optional deletion of the local copies once synced; **Disconnect from Cloud** brings everything back.
- Connection health: when the cloud is unreachable an upload fails with a clear error and nothing is written elsewhere.
- Streaming transfers (downloads to disk, uploads in blocks: 4 MiB on Azure, 5 MiB parts on S3, larger only for a file past the service's part limit), so large files do not need large memory.
- Multisite with per-site configuration, "Force HTTPS for cloud storage URLs", credentials encrypted at rest, quiet logging, clean uninstall.

## Paid, outside the plugin

- **DiluxOne hosted sync service** (in preparation, monthly fee): <https://diluxone.com/plugins-wordpress/dilux-offload/>. The plugin does not need it and nothing in the plugin is unlocked by it; until 5.0.0 connects it as one more provider, the plugin never contacts it. A report that something "does not work" because it needs this service is not a bug: it is this line.

## Next: one thing per version

Each version does one big thing, ships on its own and is usable without the next one. Numbering follows the DiluxOne policy ([`release.md`](release.md#versions)): the minors below improve what exists, and each major is a new capability (none of them breaks anything; if one ever has to, it is announced in a minor first). `main` stays publishable between steps; a fix found on the way ships as a patch of the current version without waiting. The plan 2.0.0 was built from is [`plans/2.0.0.md`](plans/2.0.0.md); each later version gets its plan before its code.

### 2.0.0: S3-compatible storage (released 28 September 2026)

1. **Tests for the settings' effects.** The suites check what Maximum File Size, Transfer Timeout, Force HTTPS and debug logging do, not only that they are saved. The timeout governs every upload request; downloads wait at least 300 seconds, or the setting when it is higher.
2. **The admin, remodelled.** One menu with a screen per submenu (Overview, Cloud Provider, Sync & Offloading, Settings, Status) and tabs only where a screen has a second level; a column on the right with the state of this site; honest figures on an offloaded site; a Connection Health table with "Check now"; buttons with their icon and label aligned; the provider's actions as buttons on Cloud Provider › Connection.
3. **An "S3-compatible" provider**, signing with AWS Signature Version 4, with a preset per service (**Amazon S3, Cloudflare R2, Backblaze B2, DigitalOcean Spaces, Wasabi, Google Cloud Storage with HMAC keys**) and **Custom** for any other server that speaks the S3 API. The public URL is its own field, which is where a CDN or custom domain goes; Test Connection proves the bucket is readable by browsers. Real-storage suites run on every change against Azure, Cloudflare R2, Google Cloud Storage and an S3 server started in CI.

### 2.1.0: safer targets, speed, more services and the options around the provider

What was planned as 2.1.0, 2.2.0 and 2.3.0, released together (all of it improves what exists, so it is one minor). The plan: [`plans/2.1.0.md`](plans/2.1.0.md).

- **A target that already holds files.** Before the first sync, the plugin lists this site's prefix in the container or bucket; if it already holds files it says how many and how much, and offers to continue, to empty it (only under this site's prefix, confirmed by typing its name) or to use another one. Nothing is deleted without that confirmation.
- **Speed.** A sync round sized by the parallelism chosen instead of a fixed 12 MB, big and small files mixed, so a library that starts with its biggest files uses the parallel uploads from the first second; large files send their parts in parallel; connections reused between rounds.
- **More services.** Presets for Hetzner, Akamai (Linode), Vultr, Scaleway, OVHcloud and IDrive e2; Backblaze B2 among the real-storage suites.
- **The options around the provider.** A browser-caching header on upload (`Cache-Control`, configurable and off-able, one week by default, new uploads only), folders the initial sync leaves out, an e-mail to the administrator when the connection fails three times and when it recovers, a test and a section in Tools › Site Health, storage class (Amazon S3, Cloudflare R2) and access tier (Azure, Hot or Cool) for new uploads, changeable at any time, never an archive class.
- **The look.** The modals and big buttons on WordPress' own button styles, notices and the admin colour scheme across every screen.

### 3.0.0: migrating between providers

More than one provider saved (tested, one of them active), and **Migrate to…**: every file copied from the active provider to another, with the same progress window as the sync (cancel, resume, verified byte for byte); offloading cannot be switched while it runs; when the copy is complete the other provider becomes the active one, and the old one keeps its files until they are deleted by hand. A permanent mirror to a second provider (a backup queue) is not part of it.

### 4.0.0: Google Cloud Storage, native

Service-account JSON and the JSON API, the way Google users expect to authenticate. Google works through the S3-compatible provider (HMAC keys) from 2.0.0.

### 5.0.0: DiluxOne Storage

The DiluxOne subscription storage as one more provider next to Azure and the S3 family, when its API exists. Azure and S3 stay complete and free; nothing in the plugin is unlocked by the subscription, and the service is disclosed under External services like every other.

### 6.0.0: Filenames

The Smart Filename plugin as a screen of Offload, off by default: unique names for uploads, with the original name kept as the attachment title and in the attachment's metadata; rewritten on Offload's rules rather than pasted in.

Requirements stay as they are: WordPress 5.1 and PHP 7.4 minimum, tested up to the current WordPress. No Composer dependency at runtime; the signing code is the plugin's own, like Azure's today.

## Later

- **Signed URLs** for private buckets or containers, so media can be served without public read access. Every provider supports it (SAS, presigned, signed URLs), but each page render signs every image URL, page and browser caches stop working past the expiry, and an image pasted into an e-mail dies with it; it stays here until someone needs it.

- The **Activity** tab with a real activity log (today it exists only as an empty template).
- **Import and export** of the plugin's configuration, with validation of what comes in.
- Locking between concurrent sync pollers, so two browser tabs cannot process the same batch.
- The remaining hard-coded English strings in the admin JavaScript, made translatable.

## Not planned

- **A local fallback** when the cloud is unreachable. An upload fails instead; nothing is ever written to `uploads/` on the server in place of the cloud.
- **URL rewriting** in post content. The stream wrapper is the whole point.
- **Locking any feature behind a payment** inside the plugin. Paid value lives in the hosted service, never in a disabled switch.

## Known limitations

- The media must be publicly readable where it is stored: an Azure container with public access level *Blob*, or a bucket that lets anyone read its objects. **Test Connection** refuses a private one.
- The initial sync runs in your browser tab and stops if you close it (it resumes where it left off). Nothing runs in the background or via cron.
- Files above the **Maximum File Size** setting (20 MB by default, up to 500 MB) are skipped by the initial sync.
- On Azure, media is served from the storage account's URL; on S3-compatible services the Public URL field is where a CDN or custom domain goes.
- **Disconnect from Cloud** needs a writable uploads directory and enough disk for your media.
