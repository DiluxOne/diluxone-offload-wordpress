# DiluxOne Offload

WordPress plugin that moves the media library to **Azure Blob Storage** and serves it from there. It works through a PHP stream wrapper on `/wp-content/uploads/`, so nothing in your posts, your database or your other plugins has to change.

[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE) [![wordpress.org](https://img.shields.io/badge/wordpress.org-diluxone--offload-21759b.svg)](https://wordpress.org/plugins/diluxone-offload/)

## Who it is for

Sites that already run on Azure, or that want their uploads off the web server: App Service and container hosts where the disk is small or ephemeral, multisite networks that share one storage account, WooCommerce and page-builder sites that cannot afford URL rewriting.

The plugin is complete and free. It ships with one provider, Azure Blob Storage, and works with your own account and key; nothing in it is held back or unlocked by anything else.

## Install

From the WordPress admin: **Plugins → Add New**, search for *DiluxOne Offload*, install, activate. Or download it from [wordpress.org/plugins/diluxone-offload](https://wordpress.org/plugins/diluxone-offload/).

To try what is coming before it is released, install the [**Development build**](https://github.com/DiluxOne/diluxone-offload-wordpress/releases/tag/dev) (Releases → the pre-release): the current state of `main`, replaced on every change, not for production sites. Its notes say which version it will become and what changed.

Then open **DiluxOne Offload → Cloud Provider**: enter the storage account, the container (public access level *Blob*) and the key, click **Test Connection** and save. In **Sync & Offloading**, start the sync and enable offloading when it finishes. Requirements, FAQ and known limitations are in [`readme.txt`](readme.txt), the text shown on wordpress.org.

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
