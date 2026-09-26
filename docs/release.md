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

## The `-dev` suffix

The PHP `Version:` header in `diluxone-offload.php` and the `DILUXONE_OFFLOAD_VERSION` constant carry a `-dev` suffix on `main` between releases. The `Stable tag:` in `readme.txt` does **not** — it always holds the last published release, or before any release the next intended one.

From the first release on, `main` cycles through these states (the example is the step after `1.0.0`):

| State | PHP `Version:` | `DILUXONE_OFFLOAD_VERSION` | readme `Stable tag:` |
| --- | --- | --- | --- |
| `main` between releases | `1.1.0-dev` | `1.1.0-dev` | `1.0.0` (last released) |
| Release-prep PR open | `1.1.0` | `1.1.0` | `1.1.0` |
| Tag `1.1.0` pushed | snapshot of the release-prep state | | |
| `main` after release | `1.2.0-dev` | `1.2.0-dev` | `1.1.0` |

Why: a developer who clones `main` between releases sees `1.1.0-dev` and immediately knows they're not looking at the published version. Without the suffix, the same clone would show `1.2.0` indistinguishable from the actual published release.

The CI version-alignment rule reads the suffix as a statement of intent rather than comparing the two values blindly:

- **With** a pre-release suffix, this is work in progress, so the base version only has to be **at or ahead of** `Stable tag:`. `1.1.0-dev` alongside a published `1.0.0` is the normal state of `main`. Falling *behind* fails — it would mean the plugin claims to be building something wp.org already serves.
- **Without** a suffix, a release is being prepared and all three markers must agree exactly. The release workflow re-checks the same thing against the git tag.

`make release` runs a dry run of the header versus `Stable tag` comparison locally; the release workflow is the one that checks all three markers against the tag.

Accepted pre-release suffixes are `-dev`, `-alpha`, `-beta`, `-rc` (optionally followed by `.N`).

## Cutting a release

Once the work for the next version is merged into `main` and CI is green:

1. **Pre-flight locally.**
   ```bash
   git checkout main
   git pull
   make release        # full quality gate + version-alignment dry-run
   ```
   `make release` will fail if PHP `Version:` (after stripping `-dev`) and readme `Stable tag:` don't match. Don't push the tag if it complains.

2. **Open a release-prep PR.** Branch name: `chore/release-X.Y.Z`. The PR does three things:
   - Drops the `-dev` suffix in `diluxone-offload.php` (the `Version:` header **and** the `DILUXONE_OFFLOAD_VERSION` constant).
   - Updates `readme.txt`: `Stable tag:` to the new version, and adds a `= X.Y.Z =` block under `== Changelog ==` summarising user-visible changes.
   - That's it. No code changes — anything that needed code change went in earlier PRs.

3. **Wait for CI to pass on the release-prep PR.** Same gates as any other PR. Notably, this is when the readme and versions check catches mismatches; `make release` should already have caught them.

4. **Merge** the release-prep PR (squash, as usual).

5. **Tag.** Bare-number, no `v` prefix:
   ```bash
   git checkout main
   git pull
   git tag X.Y.Z
   git push origin X.Y.Z
   ```

6. **The release workflow takes it from there.** [`.github/workflows/release.yml`](../.github/workflows/release.yml) fires on tag push and calls the shared [`plugin-release-wp`](https://github.com/DiluxOne/.github/blob/main/.github/workflows/plugin-release-wp.yml) workflow, which:
   - Strict tag-format validation (`^[0-9]+\.[0-9]+\.[0-9]+$`).
   - Strict version-alignment of all three markers — PHP `Version:`, `DILUXONE_OFFLOAD_VERSION`, readme `Stable tag:` — against the git tag.
   - Extracts the `= X.Y.Z =` block from the readme changelog (before touching SVN: a release without its changelog stops here) and appends what was merged since the previous release, grouped by type (✨ Features, 🐛 Fixes…).
   - [`10up/action-wordpress-plugin-deploy`](https://github.com/10up/action-wordpress-plugin-deploy) (pinned to 2.3.0) pushes `/trunk` and tags `/tags/X.Y.Z` on the wp.org SVN, and uploads `.wordpress-org/` to the SVN `/assets/` directory.
   - Creates the GitHub release with those notes as the body and the built zip attached.

7. **Verify on wp.org** within ~10 minutes. The new version should appear at `https://wordpress.org/plugins/diluxone-offload/`. wp.org does not run automated rollouts — sites with auto-update enabled pick it up over the next ~12 hours via the WordPress core update check.

8. **Bump back to dev.** Open a follow-up PR `chore/bump-(X.Y.Z+1)-dev` that:
   - Sets PHP `Version:` and `DILUXONE_OFFLOAD_VERSION` to `(next intended version)-dev`.
   - Leaves `Stable tag:` alone (it stays at the just-released `X.Y.Z`).

   Merge it. Now `main` is signposted "in development towards (next)" again.

## Required secrets

The release workflow needs two secrets. They live in the repository environment `wordpress-org`, which the deploy job runs in; its deployment policy admits only `X.Y.Z` tags, so no job of a pull request or a branch can ever read them (Settings › Environments › wordpress-org):

| Secret | What it's for |
| --- | --- |
| `SVN_USERNAME` | wp.org account username (the same one used for the plugin submission). |
| `SVN_PASSWORD` | wp.org SVN-specific password (set it at <https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password>, **not** the regular login password). |

If either is missing or wrong the deploy step prints a clear error and exits non-zero. Fix the secret (`gh secret set SVN_PASSWORD --env wordpress-org`) and use **Re-run jobs** on the failed run: it deploys the same tag again.

## Release tags are permanent

Two rulesets cover every tag shaped `X.Y.Z`: only an administrator can create one, and nobody can delete or move it, the maintainer included. That is deliberate — the tag is the record of what went to every WordPress site. So:

- **A deploy that failed before SVN** (a secret, a network error): fix the cause and **Re-run jobs**.
- **A tag on the wrong commit, or with misaligned version markers**: the tag stays. Fix it in a PR and release the next patch version.
- **A tag in the wrong shape** (`1.2`, `1.2.0-rc1`): the deploy refuses it; delete it and push the right one. `v1.2.0` matches the `*.*.*` pattern and is as permanent as a real release tag, so never push one.

## Rolling back

There is no "undo" on wp.org for a published release — once a tag is on the SVN, it's there. To roll back, ship `X.Y.Z+1` with the previous version's code. Don't try to delete the bad tag from SVN; that is more disruptive than just re-releasing.

For the GitHub side, you can delete a Release and its Git tag, but only do so if the wp.org SVN tag did *not* go out — once the SVN side has the version, the GitHub tag is the canonical historical record and shouldn't move.

## First release

`1.0.0` was the first release, after the WordPress.org Plugin Review team approved the plugin on 2026-09-23. It went out through this same tag-driven flow, with the version markers already at `1.0.0` and no `-dev` step before it.
