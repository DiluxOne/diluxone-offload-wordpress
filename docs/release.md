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

Once the work for the next version is merged into `main` and CI is green:

1. **Pre-flight locally.**
   ```bash
   git checkout main
   git pull
   make release        # full quality gate + version-alignment dry-run
   ```
   `make release` will fail if PHP `Version:` (after stripping a pre-release suffix) and readme `Stable tag:` don't match. Don't push the tag if it complains.

2. **Open a release-prep PR.** Branch name: `chore/release-X.Y.Z`. The PR does three things:
   - Sets the new version in `diluxone-offload.php` (the `Version:` header **and** the `DILUXONE_OFFLOAD_VERSION` constant).
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

6. **The release workflow takes it from there.** [`.github/workflows/release.yml`](../.github/workflows/release.yml) fires on tag push and calls the shared [`plugin-release-wp`](https://github.com/DiluxOne/.github/blob/main/.github/workflows/plugin-release-wp.yml) workflow, which: The call is pinned to a commit of `DiluxOne/.github` (not to the moving `v1` tag), because this is the one workflow that runs with the publishing credentials; Dependabot proposes the bump when the central releases. After rotating the SVN password, run **SVN credentials check** from the Actions tab: it authenticates from the environment without committing anything.
   - Strict tag-format validation (`^[0-9]+\.[0-9]+\.[0-9]+$`).
   - Strict version-alignment of all three markers — PHP `Version:`, `DILUXONE_OFFLOAD_VERSION`, readme `Stable tag:` — against the git tag.
   - Extracts the `= X.Y.Z =` block from the readme changelog (before touching SVN: a release without its changelog stops here) and appends what was merged since the previous release, grouped by type (✨ Features, 🐛 Fixes…).
   - [`10up/action-wordpress-plugin-deploy`](https://github.com/10up/action-wordpress-plugin-deploy) (pinned to 2.3.0) pushes `/trunk` and tags `/tags/X.Y.Z` on the wp.org SVN, and uploads `.wordpress-org/` to the SVN `/assets/` directory.
   - Creates the GitHub release with those notes as the body and the built zip attached.

7. **Verify on wp.org** within ~10 minutes. The new version should appear at `https://wordpress.org/plugins/diluxone-offload/`. wp.org does not run automated rollouts — sites with auto-update enabled pick it up over the next ~12 hours via the WordPress core update check.

8. **Nothing to bump back.** `main` keeps the three markers at `X.Y.Z`; every development build from here on stamps itself `<next>-dev.<N>` (see above), so no follow-up commit is needed.

## Required secrets

The release workflow needs two secrets. They live in the repository environment `wordpress-org`, which the deploy job runs in. Its deployment policy admits `X.Y.Z` tags and `main` (the by-hand credentials check runs from `main`), so a job of a pull request or of any other branch can never read them (Settings › Environments › wordpress-org). The caller passes `secrets: inherit`: it is the only way an environment's secrets reach a called workflow's job, and it also hands over every repository and organisation secret. Two things keep that acceptable: the workflow runs only on `X.Y.Z` tags (created by administrators only) or by hand from `main`, and it calls the shared workflow at a fixed commit, so what runs with those secrets is exactly the reviewed code. A Dependabot bump of that pin is therefore a secret-bearing change: read its diff in `DiluxOne/.github` before merging. The shared workflow declares no secrets of its own:

| Secret | What it's for |
| --- | --- |
| `SVN_USERNAME` | wp.org account username (the same one used for the plugin submission). |
| `SVN_PASSWORD` | wp.org SVN-specific password (set it at <https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password>, **not** the regular login password). |

If either is missing or wrong the deploy step prints a clear error and exits non-zero. Fix the secret (`gh secret set SVN_PASSWORD --env wordpress-org`) and use **Re-run jobs** on the failed run: it deploys the same tag again.

## Release tags are permanent

Two rulesets cover every tag shaped `X.Y.Z`: only an administrator can create one, and nobody can delete or move it, the maintainer included. That is deliberate — the tag is the record of what went to every WordPress site. So:

- **A deploy that failed before SVN** (a secret, a network error): fix the cause and **Re-run jobs**.
- **A tag on the wrong commit, or with misaligned version markers**: the tag stays. Fix it in a PR and release the next patch version.
- **A tag in the wrong shape** (`1.2`, `1.2.0-rc1`): the release workflow does not even start, it fires only on `X.Y.Z`. A two-part tag can be deleted and replaced; anything that matches `*.*.*` (`1.2.0-rc1`, `v1.2.0`) is as permanent as a real release tag, so never push one.

## Rolling back

There is no "undo" on wp.org for a published release — once a tag is on the SVN, it's there. To roll back, ship `X.Y.Z+1` with the previous version's code. Don't try to delete the bad tag from SVN; that is more disruptive than just re-releasing.

For the GitHub side, you can delete a Release and its Git tag, but only do so if the wp.org SVN tag did *not* go out — once the SVN side has the version, the GitHub tag is the canonical historical record and shouldn't move.

## First release

`1.0.0` was the first release, after the WordPress.org Plugin Review team approved the plugin on 2026-09-23. It went out through this same tag-driven flow, with the version markers already at `1.0.0` and no `-dev` step before it.
