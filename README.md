# DiluxOne Offload

WordPress plugin that moves the media library to **Azure Blob Storage** or to **S3-compatible storage** (Amazon S3, Cloudflare R2, Backblaze B2, DigitalOcean Spaces, Wasabi, Google Cloud Storage, MinIO) and serves it from there. It works through a PHP stream wrapper on `/wp-content/uploads/`, so nothing in your posts, your database or your other plugins has to change.

[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE) [![wordpress.org](https://img.shields.io/badge/wordpress.org-diluxone--offload-21759b.svg)](https://wordpress.org/plugins/diluxone-offload/)

## Who it is for

Sites that already run on Azure or on an S3-compatible service, or that want their uploads off the web server: App Service and container hosts where the disk is small or ephemeral, multisite networks that share one storage account or bucket, WooCommerce and page-builder sites that cannot afford URL rewriting.

The plugin is complete and free. It ships with two providers, Azure Blob Storage and S3-compatible storage, and works with your own account and keys; nothing in it is held back or unlocked by anything else.

## Where your media can live

| Provider | How it is set up | Tested against a real account on every change |
|---|---|---|
| **Azure Blob Storage** | storage account, container, key | yes |
| **Cloudflare R2** | preset: paste the account endpoint and the bucket's public URL | yes |
| **Google Cloud Storage** (HMAC keys) | preset | yes |
| **Amazon S3** | preset: region and bucket fill in the rest | not yet |
| **Backblaze B2**, **DigitalOcean Spaces**, **Wasabi** | presets | not yet |
| **Anything else that speaks the S3 API**: MinIO, Ceph, Hetzner, Akamai/Linode, Vultr, Scaleway, OVHcloud, IDrive e2, Oracle Cloud, … | *Custom*: endpoint, region and public URL typed in; path-style or virtual-hosted addressing | an S3 server started in CI (RustFS), on every pull request |

For the S3 family the **Public URL** is its own field, prefilled and always editable, which is where a CDN or a custom domain goes. **Test Connection** writes a small probe, reads it back without credentials at the public URL and deletes it, so a bucket browsers cannot read is refused before anything is saved.

## Install

From the WordPress admin: **Plugins → Add New**, search for *DiluxOne Offload*, install, activate. Or download it from [wordpress.org/plugins/diluxone-offload](https://wordpress.org/plugins/diluxone-offload/).

To try what is coming before it is released, install the [**Development build**](https://github.com/DiluxOne/diluxone-offload-wordpress/releases/tag/dev) (Releases → the pre-release): the current state of `main`, replaced on every change, not for production sites. Its notes say which version it will become and what changed.

Then open **DiluxOne Offload → Cloud Provider**: for Azure, enter the storage account, the container (public access level *Blob*) and the key; for S3-compatible storage, pick the service and enter the region, the bucket (readable by anyone), the key pair and, if the service does not fill it in, the public URL. Click **Test Connection** and save. In **Sync & Offloading**, start the sync and enable offloading when it finishes. Requirements, FAQ and known limitations are in [`readme.txt`](readme.txt), the text shown on wordpress.org.

## Hosted sync service

DiluxOne is preparing a paid, hosted sync service around this plugin, for a monthly fee: <https://diluxone.com/plugins-wordpress/dilux-offload/>. The plugin does not need it, never contacts it, and nothing in the plugin depends on it.

## How it is built

This plugin is developed with AI coding agents (Claude, through Claude Code) under human review. The maintainer reads, runs and signs every change; every pull request is also reviewed by Claude and must pass the whole quality gate (coding standards, static analysis, taint analysis, unit, integration and end-to-end tests, WordPress Plugin Check) before it can merge. How AI is used here and the rules for contributing with AI: [`docs/ai.md`](docs/ai.md).

## Run it from source

```bash
git clone https://github.com/DiluxOne/diluxone-offload-wordpress.git
cd diluxone-offload-wordpress
make install     # dev tooling into vendor/ (Docker; no PHP needed on the host)
make env         # WordPress at http://localhost:8888, admin / password
make check       # PHPCS, PHPStan level 8, Psalm taint analysis, unit tests
```

The checkout is mounted as `wp-content/plugins/diluxone-offload-wordpress/`; activate it from **Plugins**. `make help` lists everything else.

## Documentation

| Read this | For |
| --- | --- |
| [`CONTRIBUTING.md`](CONTRIBUTING.md) | Issues, branches, pull requests, what CI enforces |
| [`docs/development.md`](docs/development.md) | Local setup, Make targets, repository name vs plugin slug |
| [`docs/testing-and-quality.md`](docs/testing-and-quality.md) | Every quality gate and how to run it |
| [`docs/architecture.md`](docs/architecture.md) | How the plugin is built and the rules its code follows |
| [`docs/roadmap.md`](docs/roadmap.md) | What it does, what is paid, what comes next, what it will not do |
| [`docs/ai.md`](docs/ai.md) | How AI is used here, and the rules for AI-assisted contributions |
| [`docs/release.md`](docs/release.md) | How a change becomes a version: labels, the changelog switch, development builds, the approved release |
| [`AGENTS.md`](AGENTS.md) | The short rules any coding agent must follow |
| [`SECURITY.md`](SECURITY.md) | Private vulnerability reporting |

## About

Built by [DiluxOne](https://diluxone.com) and maintained by Pablo Di Loreto ([@soydiloreto](https://github.com/soydiloreto)). Free software under the GPL-2.0-or-later, see [LICENSE](LICENSE). Issues, forks and pull requests are welcome.
