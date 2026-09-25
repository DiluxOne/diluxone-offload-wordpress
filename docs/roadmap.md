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

## Next (October and November 2026, in no particular order)

- **Google Cloud Storage** provider, the same way Azure works today.
- **Amazon S3** provider, the same way Azure works today.
- The **DiluxOne hosted sync service** connected to the plugin as a provider.

## Later

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
