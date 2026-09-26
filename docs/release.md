# Releases: how a change becomes a version

For everyone who touches this repository: outside contributors, maintainers and coding agents. End users get the plugin from [wordpress.org/plugins/diluxone-offload](https://wordpress.org/plugins/diluxone-offload/) and never need this page. The short, enforceable version of these rules is in [`AGENTS.md`](../AGENTS.md).

## Who does what

| | Outside contributor | Maintainer | The pipeline |
| --- | --- | --- | --- |
| Writes the change and its changelog bullet | yes, in the pull request | yes | never |
| Decides the type of the change (`type:*` label) | no | can override with a label | the Claude review, from the diff |
| Computes the next version | no | no | yes, from the labels |
| Decides that a version is ready | no | yes, by removing one line in `readme.txt` | never |
| Approves the publication | no | yes, in the `wordpress-org` environment | never |
| Stamps, deploys, tags, creates the release | never | never | yes, after the approval |
| Pushes a tag `X.Y.Z` by hand | no | an administrator can; it only types the version and goes through the same job | the job refuses a tag that is not the computed, ready version |

Nobody types a version number into a file, nobody touches SVN, and the tag is created by the job. If you are contributing from outside: open the pull request with its changelog bullet and you are done; your change ships in the next version. If you are a coding agent: the rules below are the ones you must not break.

## The flow

1. **A pull request merges into `main`.** Only through a green pull request, squash-merged ([`CONTRIBUTING.md`](../CONTRIBUTING.md)). The Claude review labelled it `type:*` from the diff (a maintainer's label wins) and corrected the title's type to match.
2. **The push to `main` runs the [`Release`](../.github/workflows/release.yml) workflow.** It does not run the suites again (the tree was tested in the pull request); it computes the next version from the `type:*` labels of everything merged since the last release tag, with the organisation's [`next-version.py`](https://github.com/DiluxOne/.github/blob/main/scripts/next-version.py): `type:breaking` → major, `type:feat` → minor, `type:fix` or `type:perf` → patch; a `version:major|minor|patch` label a maintainer sets on any merged pull request wins over the types.
3. **A development build is published**, every time, whatever the labels say: the shipped tree stamped `<next>-dev.<N>`, as the one **Development build** pre-release in [Releases](https://github.com/DiluxOne/diluxone-offload-wordpress/releases), replaced on every push. See [Development builds](#development-builds).
4. **The readme says whether the version is ready.** The newest entry under `== Changelog ==` in [`readme.txt`](../readme.txt) is headed `= X.Y.Z =` and, while the version is being built, its first line is exactly `Unreleased.`. With that line in place the run ends green, its summary says **Not ready**, and nothing waits for anyone, however many pull requests merged. See [The changelog is the release switch](#the-changelog-is-the-release-switch).
5. **The maintainer removes the `Unreleased.` line in a pull request.** That is the release decision. When it merges, the run's `Deploy to wordpress.org` job waits in the `wordpress-org` environment, and only the environment's required reviewers can approve it. The summary shows the version, the bump and the pull requests that justify it.
6. **The maintainer approves the deployment** (Actions tab → the run → *Review deployments*, or the review email). Reject and nothing happens; a later push computes again. See [Approving a release](#approving-a-release).
7. **The job publishes**, from the approved commit: stamps the three version markers in its checkout (never in the repository), validates them and the changelog, commits to the wordpress.org SVN, creates the tag `X.Y.Z` with the release App's token and the GitHub release with the changelog and what was merged, grouped by type. Within about ten minutes the version is on wordpress.org; sites with auto-update pick it up over the next twelve hours.

Nothing to bump back afterwards: `main` keeps the released version in its markers, and the next development build stamps itself `<next>-dev.<N>` from the labels of what merges next.

## Versions

[Semantic Versioning](https://semver.org/) for the plugin's public version, decided by the labels:

| Label on a merged pull request | Bump | Example |
| --- | --- | --- |
| `type:fix`, `type:perf` | patch | 1.0.0 → 1.0.1 |
| `type:feat` | minor | 1.0.0 → 1.1.0 |
| `type:breaking` | major | 1.0.0 → 2.0.0 |
| `type:docs`, `type:test`, `type:ci`, `type:chore`, `type:refactor`, `type:style`, `type:build`, `type:revert` | none | nothing to release on its own |
| `version:major`, `version:minor`, `version:patch` (set by a maintainer) | that bump, whatever the types say | the roadmap calls the next version 2.0.0 although nothing breaks |

The highest bump among the merged pull requests wins. `main` keeps the last released version in its three markers, always: the `Version:` header and the `DILUXONE_OFFLOAD_VERSION` constant in `diluxone-offload.php`, and `Stable tag:` in `readme.txt`. No pull request moves them; the release job and the development builds stamp their own copies. The CI alignment check accepts a pre-release suffix on `Version:` (`-dev`, `-alpha`, `-beta`, `-rc`, optionally `.N`) with a base at or ahead of `Stable tag:`; without a suffix the three must be equal, and on `main` they are.

If the readme announces a version the labels do not reach (`= 2.0.0 =` when the labels give 1.1.0), the job refuses and says so: a maintainer settles it with a `version:*` label on a merged pull request, or by fixing the readme. The roadmap and the labels must agree before anything ships; [`docs/roadmap.md`](roadmap.md) says which version does what.

## The changelog is the release switch

The newest entry under `== Changelog ==` in `readme.txt` looks like this while a version is being built:

```
= 2.0.0 =
Unreleased.

* The "Upload Timeout" setting is now "Transfer Timeout" and governs every upload request…
* A transfer that runs past the timeout is reported as such…
```

- **Write the notes as the changes merge.** A pull request that changes what a user sees (a `feat`, a `fix`, a `perf`, a changed string or setting) adds its bullet under the `Unreleased.` line, in the same pull request; the checklist in the pull request template asks for it. Bullets are for users: what changed for them, not how.
- **Never remove the `Unreleased.` line as part of another change.** It is the switch: the pull request that removes it is the release decision, made by a maintainer, and it should contain nothing else. Removing it by accident would leave a deployment waiting for approval; nothing is published without the approval, but do not make the maintainer reject it.
- **The heading is the version the roadmap names**, `= 2.0.0 =`. A heading `= Unreleased =` is accepted too: the job renames it to the computed version in the build.
- **After the release**, the next change that deserves a bullet opens the next entry, `= X.Y.Z =` with `Unreleased.` as its first line, above the released one. A version whose merged changes are all `docs`, `test`, `ci` or `chore` never becomes a release on its own and needs no entry.

What you see in the Actions tab while the line is there: every push to `main` ends green, the `Release` run's summary says **Not ready** with the version the labels would give and the pull requests per type, and the development build is attached. A hand-pushed tag is refused with the same reason.

## Development builds

Every push to `main` publishes a build of what is coming, with no release:

| Where | What | Version shown |
| --- | --- | --- |
| Releases → **Development build** (pre-release, tag `dev`); fixed link <https://github.com/DiluxOne/diluxone-offload-wordpress/releases/download/dev/diluxone-offload.zip> | The shipped tree (minus `.distignore`), zipped, with the plugin folder named `diluxone-offload`; the notes say the version, the commit and the changelog that is coming | `2.0.0-dev.8` under Plugins; the commit under Status › System |
| `make dist` | The same tree under `build/diluxone-offload/`, from your working tree | the same, `-dirty` on the commit when you have uncommitted changes |
| `make deploy-test` | The same copied into a real site (`~/repos/cst-website` by default, `SITE=` to change it) | the same |

`<next>` is the version the labels give (the next patch when nothing is pending), `N` counts the commits since the last release tag. Both stamp the three markers in the copy and add a `Build:` header line with the commit; the plugin shows it under Status › System, so a person who installs a build knows what is coming and which commit it is. PHP orders `2.0.0-dev.8` before `2.0.0`, so a site with a development build updates to the release normally. `make dist` needs `gh` logged in to read the labels (`STAMP=0` builds the tree as it is, which is how Plugin Check runs).

**There is one development build, always the latest.** The pre-release and its tag `dev` are replaced on every push to `main`, the way WooCommerce publishes its nightly: one link, no login, no history. Released versions are different: every `X.Y.Z` keeps its tag, its GitHub release and its zip forever, and wordpress.org keeps every published version too, so a site can always go back to an earlier release. "Latest" in Releases is always the last released version, never the development build. To look at an older development build (a bug reported "on dev.7"), take the commit from its report (Status › System, or the `Build:` header) and rebuild it: `git checkout <commit> && make dist`. The issue triage knows the current released and development versions: a report made on an older one is asked to update, or to try the current development build, and to say whether the problem is still there.

## Approving a release

The `Deploy to wordpress.org` job runs in the repository environment `wordpress-org`. Its deployment policy admits `main` and `X.Y.Z` tags, and its **required reviewers** are the approval: without a reviewer the job would not wait, so the environment always has at least the maintainer. Approve in the Actions tab (the run → *Review deployments*) or from the review email; the summary of the `What is next` job shows the version, the bump and the pull requests behind it. Reject, and nothing happens. One release at a time: a push made while a run waits queues behind it and, once the first shipped, finds the tag and stops. After the approval the job checks that this is still the release to make (the labels still give this version, the commit is on `main`, the tag does not exist), then publishes. Verify on wordpress.org within about ten minutes.

**By hand.** An administrator can still push a tag `X.Y.Z` on `main`. It goes through the same job, and must be the version the labels say is next, with the readme ready; anything else is refused before SVN. A tag the job created itself starts a second run that finds the release and stops.

**Rehearsal.** `dry-run: true` in `release.yml` does everything but the SVN commit, the tag and the release, and shows the notes in the run's summary; the development build is built too, but not published. The first run of a new pipeline, and any change to it, is rehearsed that way first; the pipeline this page describes was rehearsed on 2026-09-26.

Before a release, `make release` on `main` runs the full quality gate and the marker alignment locally.

## Secrets and the release App

Everything the publication needs lives in the environment `wordpress-org`, never as a repository or organisation secret. The `Release` caller passes `secrets: inherit` (the only way an environment's secrets reach a called workflow), which is why it runs only on `main` and on `X.Y.Z` tags and calls the shared workflow at a fixed commit. After rotating the SVN password, run **SVN credentials check** from the Actions tab: it authenticates from the environment without committing anything.

| In the environment | What it's for |
| --- | --- |
| `SVN_USERNAME` (secret) | wordpress.org account username (the one used for the plugin submission). |
| `SVN_PASSWORD` (secret) | wordpress.org SVN-specific password (<https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password>, **not** the login password). |
| `DILUX_RELEASE_PRIVATE_KEY` (secret) | The private key of the GitHub App `dilux-release`. |
| `DILUX_RELEASE_CLIENT_ID` (variable) | The App's client id. |

`dilux-release` is one App for the whole DiluxOne organisation, with `contents: write` and nothing else. It is installed on each plugin repository (a token it mints is scoped to the repository whose job asked), it is in the bypass list of the ruleset that reserves `X.Y.Z` tags for administrators, and only the approved job ever holds a token from it: the review bot, `dilux-bot`, cannot create tags. If a secret is missing or wrong the job prints a clear error and exits before SVN; fix it (`gh secret set SVN_PASSWORD --env wordpress-org`) and use **Re-run jobs**. What a new plugin repository needs is in the organisation's [adoption guide](https://github.com/DiluxOne/.github#adopt-it-in-a-new-repository).

## Release tags are permanent

Two rulesets cover every tag shaped `X.Y.Z`: only an administrator or the release App can create one, and nobody can delete or move it, the maintainer included. That is deliberate: the tag is the record of what went to every WordPress site. So:

- **A deploy that failed before SVN** (a secret, a network error): fix the cause and **Re-run jobs**.
- **A tag on the wrong commit, or with misaligned version markers**: the tag stays. Fix it in a pull request and release the next patch version.
- **A tag in the wrong shape** (`1.2`, `1.2.0-rc1`): the release workflow does not even start, it fires only on `X.Y.Z`. A two-part tag can be deleted and replaced; anything that matches `*.*.*` (`1.2.0-rc1`, `v1.2.0`) is as permanent as a real release tag, so never push one. The tag `dev` of the development build is outside these rules on purpose: the pipeline moves it on every push.

## Rolling back

There is no undo on wordpress.org: once a tag is on the SVN, it's there. To roll back, ship `X.Y.Z+1` with the previous version's code. Don't try to delete the bad tag from SVN; that is more disruptive than re-releasing. On the GitHub side a release and its tag can be deleted only if the SVN tag did *not* go out; once it has, the GitHub tag is the canonical record and does not move.

## First release

`1.0.0` was the first release, after the WordPress.org Plugin Review team approved the plugin on 2026-09-23. It went out by a tag pushed by hand, with the version markers already at `1.0.0` and no development build before it; everything since follows this page.
