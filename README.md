# DiluxOne Offload

**Your WordPress media library, on Azure Blob Storage or any S3-compatible storage, without changing a single URL in your content.**

[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE) [![wordpress.org](https://img.shields.io/badge/wordpress.org-diluxone--offload-21759b.svg)](https://wordpress.org/plugins/diluxone-offload/)

DiluxOne Offload moves `/wp-content/uploads/` to Azure Blob Storage, Amazon S3, Cloudflare R2, Backblaze B2, Google Cloud Storage, DigitalOcean Spaces, Wasabi or any server that speaks the S3 API, and serves it from there. It works through a PHP stream wrapper, so your posts, your database and your other plugins keep working as they are. Free, complete, on your own account and keys.

> ### 🤖 This project is AI-first
>
> It is built with AI coding agents under human review, and it is set up so that yours can work on it too. **Clone the repository and ask your agent to read [`AGENTS.md`](AGENTS.md).** It walks it through the whole process: setting up, making the change with its tests, and running on your machine the same checks and the same AI review the pull request will get. You open the pull request only when it comes out clean.

## Who it is for

- Sites on **App Service, containers or any host with a small or ephemeral disk**.
- **Multisite networks** that share one storage account or bucket, each site in its own folder.
- **WooCommerce and page-builder sites** that cannot afford URL rewriting.
- Anyone who wants the uploads off the web server, on storage they already pay for.

## Where your media can live

| Service | How you set it up | Tested against the real service on every change |
|---|---|---|
| **Azure Blob Storage** | storage account, container, key | ✅ |
| **Cloudflare R2** | preset: account endpoint and the bucket's public URL | ✅ |
| **Google Cloud Storage** (HMAC keys) | preset | ✅ |
| **Backblaze B2** | preset | ✅ |
| **Amazon S3**, **DigitalOcean Spaces**, **Wasabi** | presets: region and bucket fill in the rest | not yet |
| **Anything else that speaks S3**: MinIO, Ceph, Hetzner, Akamai/Linode, Vultr, Scaleway, OVHcloud, IDrive e2, Oracle Cloud, … | *Custom*: endpoint, region and public URL; path-style or virtual-hosted | an S3 server (RustFS) started in CI, on every pull request |

The **Public URL** is its own field, so a CDN or a custom domain goes right there. **Test Connection** writes a small probe, reads it back without credentials and deletes it: a bucket browsers cannot read is refused before anything is saved.

## Get started

1. In WordPress: **Plugins → Add New**, search for *DiluxOne Offload*, install and activate. Or get it from [wordpress.org/plugins/diluxone-offload](https://wordpress.org/plugins/diluxone-offload/).
2. **DiluxOne Offload → Cloud Provider**: pick the service, enter the bucket or container and the keys, **Test Connection**, save.
3. **Sync & Offloading**: start the sync, and turn offloading on when it finishes.

Requirements, FAQ and known limitations are in [`readme.txt`](readme.txt), the page shown on wordpress.org.

Want what is coming before it is released? The [**Development build**](https://github.com/DiluxOne/diluxone-offload-wordpress/releases/tag/dev) is the current `main`, rebuilt on every change. Not for production sites.

## Work on it

```bash
git clone https://github.com/DiluxOne/diluxone-offload-wordpress.git
cd diluxone-offload-wordpress
make install    # dev tools into vendor/ (Docker and Node.js; no PHP needed on your machine)
make env        # WordPress at http://localhost:8888, admin / password
# make the change, commit, write the description in build/pr.md, then:
make pre-pr REVIEW_ARGS="--title 'fix(sync): what it does' --body-file build/pr.md"
```

Then tell your agent to read [`AGENTS.md`](AGENTS.md), or read it yourself: it is short. The same review that runs on every pull request runs on your machine first, and writes what to fix in `.git/dx-review/findings.md`; when it says **Ready for a pull request**, open one. `make help` lists everything else.

How AI is used here, and the rules for AI-assisted contributions: [`docs/ai.md`](docs/ai.md). Every change is still read, run and signed by the maintainer.

## Documentation

| Read this | For |
| --- | --- |
| [`AGENTS.md`](AGENTS.md) | **Start here**: the workflow step by step, and the rules any agent must follow |
| [`CONTRIBUTING.md`](CONTRIBUTING.md) | Issues, branches, pull requests, what CI enforces |
| [`docs/development.md`](docs/development.md) | Local setup, Make targets, repository name vs plugin slug |
| [`docs/testing-and-quality.md`](docs/testing-and-quality.md) | Every quality gate and how to run it |
| [`docs/architecture.md`](docs/architecture.md) | How the plugin is built and the rules its code follows |
| [`docs/roadmap.md`](docs/roadmap.md) | What it does, what comes next, what it will not do |
| [`docs/release.md`](docs/release.md) | How a change becomes a version |
| [`docs/ai.md`](docs/ai.md) | How AI is used here |
| [`SECURITY.md`](SECURITY.md) | Reporting a vulnerability privately |

## Hosted sync service

DiluxOne is preparing a paid, hosted sync service around this plugin: <https://diluxone.com/plugins-wordpress/dilux-offload/>. The plugin does not need it, never contacts it, and nothing in it depends on it.

## About

Built by [DiluxOne](https://diluxone.com) and maintained by Pablo Di Loreto ([@soydiloreto](https://github.com/soydiloreto)). Free software under the GPL-2.0-or-later, see [LICENSE](LICENSE). Issues, forks and pull requests are welcome.
