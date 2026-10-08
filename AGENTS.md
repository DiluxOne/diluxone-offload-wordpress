# AGENTS.md

<!-- dx:org:start (kept in step by DiluxOne/.github, scripts/sync-repos.py; edit it there) -->
## The organisation's rules (the same in every DiluxOne repository)

You are working in a DiluxOne repository. Whoever you work for (the
maintainer, or someone contributing from a fork), these hold here, and the
step-by-step flow with its commands is in
[DiluxOne/.github, `docs/agents.md`](https://github.com/DiluxOne/.github/blob/main/docs/agents.md):

1. **Nothing starts without an accepted issue.** Find the issue, or open one
   with the form that fits (it sets the issue's Type: Bug, Feature, Docs or
   Task), and wait until a maintainer adds the `accepted` label. Never add it
   yourself. The pull request says `Closes #<number>`; CI fails it otherwise.
2. **The pull request's type fits the issue's Type**: `feat` (or any `!`)
   closes a Feature, `fix` a Bug; other types close any accepted issue.
3. **Branch** `<type>/<number>-<short-kebab>`; **commit and title** as a
   Conventional Commit of 72 characters or fewer (CI rejects more than 100);
   bodies are plain paragraphs, never hard-wrapped; no `Claude-Session:`.
4. **Check before the pull request** (`dx check`, or the repository's
   `make pre-pr`) and open it (`dx pr`) only when the person you work for
   says so.
5. **Never** push to `main`, create or move a tag, or approve a release.
6. **Say AI took part** with one line at the end of what you write on GitHub:
   `🤖 AI-generated · <model> (<maker>)` when you wrote it,
   `🤖 AI-assisted · <model> (<maker>)` when a person did with your help.
   Never "Generated with …".
<!-- dx:org:end -->

Instructions for any coding agent working in this repository (Claude Code,
Codex, Cursor, …), and for the person it works with. If someone just cloned
this repository and asked you to read this file: the organisation's rules
above hold first; then start with "Your workflow" below and follow it step by
step. Humans: the same rules live in the organisation's
[contributing guide](https://github.com/DiluxOne/.github/blob/main/CONTRIBUTING.md),
[`CONTRIBUTING.md`](CONTRIBUTING.md) and [`docs/ai.md`](docs/ai.md); this file
is the short version an agent must follow without exception.

## What this is

DiluxOne Offload, a WordPress plugin published on wordpress.org as
`diluxone-offload`. It moves the media library to Azure Blob Storage or to
S3-compatible storage through a PHP stream wrapper. Architecture, hard rules
and review priorities: [`docs/architecture.md`](docs/architecture.md).

The repository is `diluxone-offload-wordpress`; the slug and text domain are
`diluxone-offload`. Before touching a path or a workflow, check which of the
two it needs ([`docs/development.md`](docs/development.md#the-repository-name-is-not-the-plugin-slug)).

## Your workflow

Everything a pull request is checked on can run on this machine, and it runs
here first: a pull request is opened only when the branch is already clean,
so it is reviewed on GitHub once. Every push to an open pull request is a
paid review and a round of waiting.

1. **Set up once.** You need Docker, Node.js (for `npx`), GNU make, git,
   `gh` signed in, a checkout of [DiluxOne/.github](https://github.com/DiluxOne/.github)
   beside this one (`../.github`, for `dx`), and the Claude Code CLI for
   step 6; no PHP on the host. Then run
   `make install` (the dev tools, in `vendor/`), `make env` (WordPress at
   http://localhost:8888, admin / password, with the plugin mounted; a
   second site at :8889 for the tests) and `make env-multisite` (turns that
   second site into a network). After a restart, `make env` again.
2. **An accepted issue, then its branch:** `dx start <number>` checks the
   issue is accepted and creates `<type>/<number>-<slug>` from `main`.
3. **Make the change** with its tests at every layer it touches (unit,
   integration, end-to-end, and the real-storage suites when storage
   behaviour changes), the docs that describe it, and, when a user notices
   it, one bullet under the newest `= X.Y.Z =` entry of `readme.txt`, below
   its `Unreleased.` line.
4. **Commit** as the organisation's rules above say.
5. **Write the pull request description** in `.git/dx/pr.md`, which
   `dx start` wrote already closing the issue: 📝 What changes and 💡 Why
   are required.
6. **Run everything:**
   ```bash
   make pre-pr REVIEW_ARGS="--title 'fix(sync): what it does' --body-file .git/dx/pr.md"
   ```
   One after the other, it runs what a pull request is checked on:
   `make check` (PHPCS, PHPStan level 8, Psalm taint, unit tests), the
   string extraction CI fails on any warning of (`make i18n-check`), the unit
   tests again on PHP 7.4, the minimum (`make test-unit-min`), the docs
   check (`make docs-check`: links resolve, no retired product name), the
   integration suite, the end-to-end suite, wordpress.org's Plugin Check in
   strict mode (a warning fails), the kind's review rules (`make
   review-rules`: every suppression listed with its reason in
   `.github/review-suppressions.yml`), and the local review: the same
   conventions, accepted-issue gate, risk floor and Claude review the pull
   request will get, from the organisation's shared scripts
   ([DiluxOne/.github](https://github.com/DiluxOne/.github)), on your own
   Claude account (it uses that account's quota, like any Claude Code
   session). Any step can run alone (`make review-local` is the last one).
   A change to docs only (Markdown, `docs/`, no code) needs just
   `make docs-check` and `make review-local`: on GitHub such a pull request
   skips the slow suites too. What stays on GitHub: the unit tests on
   PHP 8.0 to 8.5, the real-storage suites and CodeQL. Run
   `make screenshots` when a listing screen changes; when the change touches
   storage, the real-storage journeys against a local S3 server need no
   keys: `make s3-up && make test-real REAL_PROVIDER=s3` (`s3-up` adds one
   line to `/etc/hosts` the first time, with sudo).
7. **Fix what it found.** The review writes `.git/dx-review/findings.md`: a
   list with the file and line of each finding. Fix every blocker and major
   (and the minors that are cheap), commit, and run step 6 again: the next
   run reviews only what changed and says which findings are fixed. Repeat
   until it says **Ready for a pull request**.
8. **Only then open the pull request** (`dx pr`), with that title and that
   description, and only when the person you work for says so. On GitHub the
   same review runs again, and so do the end-to-end suite and the
   real-storage suites (those need the repository's keys: a fork's pull
   request gets them after the merge).
   If the review there leaves findings, fix them all locally, run step 6, and
   push once.

What cannot run here without keys: the real-storage suites against Azure,
R2, Google and Backblaze B2. A maintainer with the keys runs them with
`make test-real` ([`docs/testing-and-quality.md`](docs/testing-and-quality.md)).

## How work reaches `main`

Only through a pull request that closes an accepted issue, squash-merged;
nobody pushes to `main`, admins included. The organisation's pipeline
(`org-pull-request.yml`, required by its `main` ruleset) holds every pull
request to the rules above: the conventions, the accepted issue, the
description (📝 What changes and 💡 Why), the Claude review and the
auto-merge decision. This repository's own checks are
[`plugin-checks.yml`](.github/workflows/plugin-checks.yml), required by its
"own checks" ruleset.

## Tests and checks

A change carries its tests at every layer it touches, in the same pull
request: unit, integration, end-to-end without a cloud account, the
real-storage suite on a single site and on a network, and the listing
screenshots (`make screenshots`) when a screen changes
([`docs/testing-and-quality.md`](docs/testing-and-quality.md)).

Every job that runs on a pull request is a required check on `main` (the
organisation's pipeline, and this repository's checks, tests and real-storage
suites through its "own checks" ruleset), except CodeQL, which only runs when
JavaScript changes (`release.yml` runs on
pushes to `main` and on release tags, never on a pull request). Every pull
request is also reviewed by Claude, which labels its risk, its complexity and
its type (`type:*`, from which the next version is computed); only low-risk
changes can merge without a human.

## How a change becomes a release

The whole flow, with who does what, is [`docs/release.md`](docs/release.md).
What you must do, and never do, in a change:

- **The version is computed, never typed.** The `type:*` label the review
  sets on each merged pull request gives the next number (`breaking` →
  major, `feat` → minor, `fix`/`perf` → patch); a maintainer's `version:*`
  label wins, and `version:major` is how a big new capability becomes a
  major ([`docs/release.md`](docs/release.md#versions)). A breaking change
  ships only in a major. The three version markers say the last version
  released, or, from its release pull request on, the one being released;
  CI holds every pull request to that.
- **Write the changelog in the same pull request.** A change a user notices
  adds one bullet under the newest `= X.Y.Z =` entry of `readme.txt`, below
  its first line `Unreleased.`, written for users.
- **The line `Unreleased.` is the release switch.** While it is there, every
  push to `main` ends green as **Not ready** and nothing waits for approval.
  The pull request that removes it is the maintainer's decision to release
  and contains nothing else but the version markers set to that version
  (`scripts/release-markers.sh prepare` in DiluxOne/.github). Never remove
  it as part of another change.
- **Every push to `main` publishes a development build**, the shipped tree
  stamped `<next>-dev.<N>`, as the one **Development build** pre-release in
  Releases (tag `dev`, replaced each time, never "Latest"). That is how a
  change is tried before a release; `make dist` builds the same locally.
  Never create, move or delete the tag `dev` by hand: the pipeline owns it.
- **Publishing is a deployment a maintainer approves** in the `wordpress-org`
  environment; the job then checks the markers, deploys, tags and creates
  the release.
  No agent does any of that by hand. An administrator may still push a tag
  `X.Y.Z`; it only types the version and goes through the same job, which
  refuses anything but the computed, ready version.

## Rules you must not break

- **Never** push to `main`, create or push a tag, create a GitHub release,
  approve a deployment or touch the wordpress.org SVN. A tag `X.Y.Z` is
  created by the release job after the maintainer's approval and publishes
  the plugin to every WordPress site; release tags are permanent
  ([`docs/release.md`](docs/release.md)).
- **Never** bump the version markers, and never remove the `Unreleased.`
  line of the newest changelog entry, unless the maintainer asked for that
  release: then both go in one pull request of their own. The next version
  is computed from the `type:*` labels of what merged; development builds
  stamp their own copies ([`docs/release.md`](docs/release.md)).
- **Never** commit secrets: no `.env*`, no storage keys, no SVN password. The
  real-storage suite reads its key from the environment or a git-ignored
  `.env.e2e`.
- **PHP 7.4 and WordPress 5.1** are the minimums; the runtime has no Composer
  dependencies and `vendor/` never ships.
- **No local fallback.** When the cloud is down an upload fails; nothing is
  written to `uploads/` on the server instead.
- **Every failure path of a provider call cleans up.** A non-2xx status, a
  transport error and a `200` with an error body all run the same cleanup
  (the `on_failure` callback, AbortMultipartUpload), including a commit run
  later through `curl_multi`; nothing is left stored and billed.
- **Every user-facing string** goes through a translation function with the
  `diluxone-offload` text domain; input is sanitized, output escaped, SQL
  prepared, credentials never logged.
- **Docs change in the same PR as the behaviour they describe.** That
  includes this file, `docs/architecture.md`, `readme.txt` and `docs/`.
  A doc that describes something the code no longer does is a bug.

## Where the details are

| Question | Read |
| --- | --- |
| How the plugin works, its hard rules, what the review looks for | [`docs/architecture.md`](docs/architecture.md) |
| Local setup, Make targets, repository name vs plugin slug | [`docs/development.md`](docs/development.md) |
| Every quality gate, what runs when, how to run each | [`docs/testing-and-quality.md`](docs/testing-and-quality.md) |
| Issues, branches, titles, pull requests, the review | [DiluxOne/.github, `docs/agents.md`](https://github.com/DiluxOne/.github/blob/main/docs/agents.md) and its `CONTRIBUTING.md` |
| Forks and the real-storage suites, code rules | [`CONTRIBUTING.md`](CONTRIBUTING.md) |
| Versions, the changelog switch, development builds, approving a release | [`docs/release.md`](docs/release.md) |
| What is free, paid, planned, not planned; which version does what | [`docs/roadmap.md`](docs/roadmap.md) |
| How AI is used here and the rules for AI-assisted work | [`docs/ai.md`](docs/ai.md) |
| The shared workflows, policy and review profiles | [DiluxOne/.github](https://github.com/DiluxOne/.github) |
