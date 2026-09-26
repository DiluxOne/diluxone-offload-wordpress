# Roadmap

What DiluxOne Offload does, what is paid, what comes next and what it will not do. Kept current by the maintainer; the issue triage and the code review read it, so a report about something listed here gets the right answer without a human.

## What it does today (free, in the plugin)

- Moves the media library to **Azure Blob Storage** and serves it from there, through a PHP stream wrapper on `/wp-content/uploads/`: no URL rewriting, no database migration.
- Sync with cancel, resume and retry; optional deletion of the local copies once synced; **Disconnect from Cloud** brings everything back.
- Connection health: when the cloud is unreachable an upload fails with a clear error and nothing is written elsewhere.
- Streaming transfers (downloads to disk, uploads in 4 MiB blocks), so large files do not need large memory.
- Multisite with per-site configuration, "Force HTTPS for cloud storage URLs", credentials encrypted at rest, quiet logging, clean uninstall.

## Paid, outside the plugin

- **DiluxOne hosted sync service** (in preparation, monthly fee): <https://diluxone.com/plugins-wordpress/dilux-offload/>. The plugin does not need it, never contacts it, and nothing in the plugin is unlocked by it. A report that something "does not work" because it needs this service is not a bug: it is this line.

## Next: one thing per version

Each version does one big thing, ships on its own and is usable without the next one. `main` stays publishable between steps; a fix found on the way ships as a patch of the current version without waiting. The detailed plan for the next version is [`plans/2.0.0.md`](plans/2.0.0.md).

### 2.0.0: S3-compatible storage (October to November 2026)

1. **Tests for the settings' effects.** Today the suites check that Maximum File Size, Upload Timeout, Force HTTPS and debug logging are saved; they will also check what each one does. The timeout becomes "Transfer Timeout" and governs every upload request; downloads keep waiting at least the 300 seconds they always had, or the setting when it is higher.
2. **The admin, remodelled.** One menu with a screen per submenu (Overview, Cloud Provider, Sync & Offloading, Settings, Status) and tabs only where a screen has a second level; every screen titled `DiluxOne Offload | Screen`; a column on the right with the state of this site, a note and the related links; WordPress' own buttons, tabs and form rows; the cards, the sync flow and the health banner as they are. The numbers on the Sync screens become honest on an offloaded site: the tracking table is no longer emptied after Delete Local Files, live uploads are recorded, skipped files are listed, a Connection Health table with "Check now", free disk before Disconnect.
3. **An "S3-compatible" provider.** One provider in the code, signing requests with AWS Signature Version 4, and a preset per service that fills in the endpoint, the region rule and the public URL pattern: **Amazon S3, Cloudflare R2, Backblaze B2, DigitalOcean Spaces, Wasabi, Google Cloud Storage (HMAC keys)** and **Custom** (MinIO or any other, every field editable). The **public URL** is a field of its own, prefilled by the preset and always editable, which is also how a CDN or a custom domain is used; Cloudflare R2 needs it typed, since R2's public URL cannot be derived from the credentials. **Test Connection** checks both that the keys can write to the bucket and that the written object is readable anonymously at the public URL, the way it refuses a private container on Azure today. An Advanced tab holds the object ACL (only for services that have one) and the addressing style. Real-storage suites run against Amazon S3 and Cloudflare R2, and against MinIO locally and on every pull request.

### 2.1.0: the options around the provider

Small settings that the remodelled screens have room for and the audit of the mock-up found honest: a browser-caching header on upload (`Cache-Control`, one week by default, new uploads only), path prefixes skipped by the initial sync, an e-mail to the administrator when the connection fails three times and when it recovers, a section and a test in Tools › Site Health, storage class (Amazon S3, Cloudflare R2) and access tier (Azure, Hot or Cool) on the Advanced tab.

### 3.0.0: Google Cloud Storage, native

Service-account JSON and the JSON API, the way Google users expect to authenticate. Google works through the S3-compatible provider (HMAC keys) from 2.0.0.

### 4.0.0: Filenames

The Smart Filename plugin as a screen of Offload, off by default: unique names for uploads, with the original name kept as the attachment title and in the attachment's metadata; rewritten on Offload's rules rather than pasted in.

### After that

The **DiluxOne hosted sync service** connected to the plugin as a provider, when its API exists.

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

- One provider today: Azure Blob Storage. The container must allow anonymous read of blobs (public access level *Blob*); a private container is refused.
- The initial sync runs in your browser tab and stops if you close it (it resumes where it left off). Nothing runs in the background or via cron.
- Files above the **Maximum File Size** setting (20 MB by default, up to 500 MB) are skipped by the initial sync.
- No CDN or custom-domain option: media is served from your storage account's URL.
- **Disconnect from Cloud** needs a writable uploads directory and enough disk for your media.
