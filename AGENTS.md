# AGENTS.md

Instructions for any coding agent working in this repository (Claude Code,
Codex, Cursor, …), and for the person it works with. If someone just cloned
this repository and asked you to read this file: start with "Your workflow"
below and follow it step by step. Humans: the same rules live in
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

1. **Set up once.** You need Docker, Node.js (for `npx`), GNU make and git,
   and the Claude Code CLI for step 6; no PHP on the host. Then run
   `make install` (the dev tools, in `vendor/`), `make env` (WordPress at
   http://localhost:8888, admin / password, with the plugin mounted; a
   second site at :8889 for the tests) and `make env-multisite` (turns that
   second site into a network). After a restart, `make env` again.
2. **Branch from `main`:** `<type>/<kebab-case>` (see the rules below).
3. **Make the change** with its tests at every layer it touches (unit,
   integration, end-to-end, and the real-storage suites when storage
   behaviour changes), the docs that describe it, and, when a user notices
   it, one bullet under the newest `= X.Y.Z =` entry of `readme.txt`, below
   its `Unreleased.` line.
4. **Commit** with Conventional Commit headers of at most 100 characters.
5. **Write the pull request description** in a file, say `build/pr.md`
   (git-ignored), with the template's sections
   ([`.github/pull_request_template.md`](.github/pull_request_template.md)):
   📝 What changes and 💡 Why are required.
6. **Run everything:**
   ```bash
   make pre-pr REVIEW_ARGS="--title 'fix(sync): what it does' --body-file build/pr.md"
   ```
   One after the other, it runs what a pull request is checked on:
   `make check` (PHPCS, PHPStan level 8, Psalm taint, unit tests), the unit
   tests again on PHP 7.4, the minimum (`make test-unit-min`), the docs
   check (`make docs-check`: links resolve, no retired product name), the
   integration suite, the end-to-end suite, wordpress.org's Plugin Check,
   and the local review: the same conventions, risk floor and Claude review
   the pull request will get, from the organisation's shared scripts
   ([DiluxOne/.github](https://github.com/DiluxOne/.github)), on your own
   Claude account (it uses that account's quota, like any Claude Code
   session). Any step can run alone (`make review-local` is the last one).
   A change to docs only (Markdown, `docs/`, no code) needs just
   `make docs-check` and `make review-local`: on GitHub such a pull request
   skips the slow suites too. Only two things stay on GitHub: the unit tests on PHP 8.0 to 8.5
   and the real-storage suites. Run `make screenshots` when a listing screen
   changes; when the change touches
   storage, the real-storage journeys against a local S3 server need no
   keys: `make s3-up && make test-real REAL_PROVIDER=s3` (`s3-up` adds one
   line to `/etc/hosts` the first time, with sudo).
7. **Fix what it found.** The review writes `.git/dx-review/findings.md`: a
   list with the file and line of each finding. Fix every blocker and major
   (and the minors that are cheap), commit, and run step 6 again: the next
   run reviews only what changed and says which findings are fixed. Repeat
   until it says **Ready for a pull request**.
8. **Only then push and open the pull request**, with that title and that
   description, and only when the person you work for says so. On GitHub the
   same review runs again, the end-to-end suite and the real-storage suites
   run too (they need the repository's keys; forks get them after the merge).
   If the review there leaves findings, fix them all locally, run step 6, and
   push once.

What cannot run here without keys: the real-storage suites against Azure,
R2, Google and Backblaze B2. A maintainer with the keys runs them with
`make test-real` ([`docs/testing-and-quality.md`](docs/testing-and-quality.md)).

## How work reaches `main`

Only through a pull request, squash-merged. Nobody pushes to `main`, admins
included. CI enforces every rule in this section (the shared `conventions`
workflow from `DiluxOne/.github`); a PR that breaks one cannot merge.

- **Branch:** `<type>/<kebab-case>`, e.g. `fix/sync-retry-count`.
- **PR title:** a Conventional Commit header, `type(scope): subject`, at most
  100 characters, no trailing period. It becomes the commit on `main`.
- **Every commit on the branch:** the same header format.
- **Types:** `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`,
  `build`, `ci`, `chore`, `revert`.
- **Trailers:** `Co-Authored-By:` is fine. `Claude-Session:` and other
  session links are rejected.
- **Messages** say what the change does and why, not who or what wrote it.
  The body is plain paragraphs, one line each, never hard-wrapped.
- **PR description:** fill the template's "📝 What changes" and "💡 Why"
  sections; CI fails when either is empty. It becomes the commit body.
- **AI line:** end every PR, issue or comment you write with
  `🤖 AI-generated · <model> (Anthropic)` (`AI-assisted` when a person wrote
  it with your help). Never "Generated with …": CI rejects it.

## Tests and checks

A change carries its tests at every layer it touches, in the same pull
request: unit, integration, end-to-end without a cloud account, the
real-storage suite on a single site and on a network, and the listing
screenshots (`make screenshots`) when a screen changes
([`docs/testing-and-quality.md`](docs/testing-and-quality.md)).

Every job that runs on a pull request is a required check on `main`, except
CodeQL, which only runs when JavaScript changes (`release.yml` runs on
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
| Branches, titles, pull requests, forks, the review | [`CONTRIBUTING.md`](CONTRIBUTING.md) |
| Versions, the changelog switch, development builds, approving a release | [`docs/release.md`](docs/release.md) |
| What is free, paid, planned, not planned; which version does what | [`docs/roadmap.md`](docs/roadmap.md) |
| How AI is used here and the rules for AI-assisted work | [`docs/ai.md`](docs/ai.md) |
| The shared workflows, policy and review profiles | [DiluxOne/.github](https://github.com/DiluxOne/.github) |
