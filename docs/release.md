# Release process

For maintainers. End users get the plugin from [wordpress.org/plugins/diluxone-offload](https://wordpress.org/plugins/diluxone-offload/) — they don't need to read this document.

## Versioning

We follow [Semantic Versioning](https://semver.org/) for the plugin's public version (`MAJOR.MINOR.PATCH`):

| Bump | When |
| --- | --- |
| **PATCH** (1.1.0 → 1.1.1) | Bug fixes only, no behaviour change beyond the fix itself. |
| **MINOR** (1.1.0 → 1.2.0) | New user-visible functionality, backwards-compatible. |
| **MAJOR** (1.x → 2.0)     | Backwards-incompatible changes. Avoid unless truly necessary. |

Repository-only changes (CI, dev tooling, this `docs/` directory, …) **do not** trigger a version bump. Those files are excluded from the wp.org deploy via [`.distignore`](../.distignore) and are invisible to end users.

## Version markers and development builds

Three markers carry the version: the `Version:` header and the `DILUXONE_OFFLOAD_VERSION` constant in `diluxone-offload.php`, and `Stable tag:` in `readme.txt`. On `main` all three hold the **last released version**, always; no commit moves them between releases. What moves is the build:

| Build | `Version:` / constant / `Stable tag:` | `Build:` header |
| --- | --- | --- |
| `main` as committed | `1.0.0` (last released) | none |
| `make dist`, `make deploy-test` | `1.1.0-dev.14` | `a1b2c3d`, `a1b2c3d-dirty` with uncommitted changes |
| the release | `1.1.0` | none |

The development version is computed, never typed: `<next>` comes from the `type:*` labels of the pull requests merged since the last tag (a feature pending → minor, a fix → patch, a breaking change → major; nothing pending → the next patch), by the organisation's [`next-version.py`](https://github.com/DiluxOne/.github/blob/main/scripts/next-version.py); `N` counts the commits since the tag. A person who installs a build sees `1.1.0-dev.14` under Plugins and the commit under Status › System, and knows what is coming and which build it is. PHP orders `1.1.0-dev.14` before `1.1.0`, so a site with a development build updates to the release normally.

The CI version-alignment rule still accepts a pre-release suffix on `Version:` (`-dev`, `-alpha`, `-beta`, `-rc`, optionally `.N`): with one, the base version must be at or ahead of `Stable tag:`; without one, all three markers must agree exactly, and the release workflow checks them against the tag. `main` has no suffix, so on `main` the three are simply equal.

## Cutting a release

A release is a **deployment that a person approves**, not a commit. [`.github/workflows/release.yml`](../.github/workflows/release.yml) runs on every push to `main` and calls the shared [`plugin-release-wp`](https://github.com/DiluxOne/.github/blob/main/.github/workflows/plugin-release-wp.yml) workflow, pinned to a commit of `DiluxOne/.github` (never the moving tag: this is the one workflow that runs with the publishing credentials; Dependabot proposes the bump when the central releases).

1. **The version is computed.** The `What is next` job runs the organisation's [`next-version.py`](https://github.com/DiluxOne/.github/blob/main/scripts/next-version.py): the `type:*` labels of the pull requests merged since the last tag give the bump (`type:breaking` → major, `type:feat` → minor, `type:fix` or `type:perf` → patch; a `version:major|minor|patch` label a person sets wins). Nothing pending, the run ends green and its summary says so. Nothing to type anywhere.

2. **The readme announces it.** The newest entry under `== Changelog ==` in `readme.txt` is either `= X.Y.Z =` with the version the labels reach, or `= Unreleased =`, which the job renames (a first line saying just "Unreleased." goes too). Write the changelog in the pull requests that earn it, as they merge. If the readme says `2.0.0` and the labels only reach `1.1.0`, the job refuses and says so: put `version:major` on the pull request that justifies the major, or fix the readme. The roadmap and the labels must agree before anything ships.

3. **A person approves.** The `Deploy to wordpress.org` job waits in the `wordpress-org` environment. Its summary shows the version, the bump and the pull requests behind it. Approve in the Actions tab (or the deployment review email) and the job goes on; reject and nothing happens; merge more and a later push computes again. Only the environment's required reviewers can approve.

4. **The job does the rest**, from the approved commit: stamps the three markers to `X.Y.Z` in its checkout (`main` is never touched), validates them and the changelog, commits `/trunk`, `/tags/X.Y.Z` and `.wordpress-org/` → `/assets` to the wordpress.org SVN ([`10up/action-wordpress-plugin-deploy`](https://github.com/10up/action-wordpress-plugin-deploy), pinned), creates the git tag `X.Y.Z` on that commit with the release App's token, and the GitHub release with the changelog and what was merged since the previous release, grouped by type (✨ Features, 🐛 Fixes…), with the zip attached.

5. **Verify on wp.org** within ~10 minutes at `https://wordpress.org/plugins/diluxone-offload/`. Sites with auto-update pick it up over the next ~12 hours.

Nothing to bump back: `main` keeps the released version in its markers, and every development build stamps itself `<next>-dev.<N>`.

**By hand.** An administrator can still push a tag `X.Y.Z` on `main`. It goes through the same job, and must be the version the labels say is next; anything else is refused before SVN. A tag the job created itself starts a second run that finds the release and stops.

**Rehearsal.** `dry-run: true` in `release.yml` does everything but the SVN commit and the tag, and leaves the GitHub release as a draft to inspect. The first run of a new pipeline, and any change to it, is rehearsed that way first.

Before the release, a `make release` on `main` still runs the full quality gate and the marker alignment locally.

## Required secrets and the release App

The release job runs in the repository environment `wordpress-org`, whose deployment policy admits `main` and `X.Y.Z` tags and whose **required reviewers** are the approval. Everything secret lives there, never as a repository or organisation secret. After rotating the SVN password, run **SVN credentials check** from the Actions tab: it authenticates from the environment without committing anything.

| In the environment | What it's for |
| --- | --- |
| `SVN_USERNAME` (secret) | wp.org account username (the same one used for the plugin submission). |
| `SVN_PASSWORD` (secret) | wp.org SVN-specific password (set it at <https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password>, **not** the regular login password). |
| `DILUX_RELEASE_PRIVATE_KEY` (secret) | The private key of the GitHub App `dilux-release`, which has `contents: write` and nothing else, is installed on this repository and is in the bypass list of the ruleset that reserves `X.Y.Z` tags for administrators. Only the approved job ever holds a token from it. |
| `DILUX_RELEASE_CLIENT_ID` (variable) | The App's client id. |

If a secret is missing or wrong the job prints a clear error and exits non-zero before SVN. Fix it (`gh secret set SVN_PASSWORD --env wordpress-org`) and use **Re-run jobs** on the failed run.

## Release tags are permanent

Two rulesets cover every tag shaped `X.Y.Z`: only an administrator or the release App can create one, and nobody can delete or move it, the maintainer included. That is deliberate — the tag is the record of what went to every WordPress site. So:

- **A deploy that failed before SVN** (a secret, a network error): fix the cause and **Re-run jobs**.
- **A tag on the wrong commit, or with misaligned version markers**: the tag stays. Fix it in a PR and release the next patch version.
- **A tag in the wrong shape** (`1.2`, `1.2.0-rc1`): the release workflow does not even start, it fires only on `X.Y.Z`. A two-part tag can be deleted and replaced; anything that matches `*.*.*` (`1.2.0-rc1`, `v1.2.0`) is as permanent as a real release tag, so never push one.

## Rolling back

There is no "undo" on wp.org for a published release — once a tag is on the SVN, it's there. To roll back, ship `X.Y.Z+1` with the previous version's code. Don't try to delete the bad tag from SVN; that is more disruptive than just re-releasing.

For the GitHub side, you can delete a Release and its Git tag, but only do so if the wp.org SVN tag did *not* go out — once the SVN side has the version, the GitHub tag is the canonical historical record and shouldn't move.

## First release

`1.0.0` was the first release, after the WordPress.org Plugin Review team approved the plugin on 2026-09-23. It went out through this same tag-driven flow, with the version markers already at `1.0.0` and no `-dev` step before it.
