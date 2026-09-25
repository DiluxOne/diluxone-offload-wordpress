# AGENTS.md

Instructions for any coding agent working in this repository (Claude Code,
Codex, Cursor, …). Humans: the same rules live in
[`CONTRIBUTING.md`](CONTRIBUTING.md) and [`docs/ai.md`](docs/ai.md);
this file is the short version an agent must follow without exception.

## What this is

DiluxOne Offload, a WordPress plugin published on wordpress.org as
`diluxone-offload`. It moves the media library to Azure Blob Storage through
a PHP stream wrapper. Architecture, hard rules and review priorities:
[`docs/architecture.md`](docs/architecture.md).

The repository is `diluxone-offload-wordpress`; the slug and text domain are
`diluxone-offload`. Before touching a path or a workflow, check which of the
two it needs ([`docs/development.md`](docs/development.md#the-repository-name-is-not-the-plugin-slug)).

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

## Before you push

```bash
make check               # PHPCS, PHPStan level 8, Psalm taint, unit tests
make test-integration    # needs make env
make plugin-check        # wordpress.org's Plugin Check on the built dist
```

Every job that runs on a pull request is a required check on `main`, except
CodeQL, which only runs when JavaScript changes (`release.yml` runs on tags
only). Every pull request is also reviewed by Claude, which labels its risk
and complexity; only low-risk changes can merge without a human.

## Rules you must not break

- **Never** push to `main`, create or push a tag, create a GitHub release or
  touch the wordpress.org SVN. Pushing a tag `X.Y.Z` publishes the plugin to
  every WordPress site; release tags are permanent. Releases are cut by the
  maintainer ([`docs/release.md`](docs/release.md)).
- **Never** bump the version in a feature PR. The version changes only in a
  release-prep PR.
- **Never** commit secrets: no `.env*`, no storage keys, no SVN password. The
  real-storage suite reads its key from the environment or a git-ignored
  `.env.e2e`.
- **PHP 7.4 and WordPress 5.1** are the minimums; the runtime has no Composer
  dependencies and `vendor/` never ships.
- **No local fallback.** When the cloud is down an upload fails; nothing is
  written to `uploads/` on the server instead.
- **Every user-facing string** goes through a translation function with the
  `diluxone-offload` text domain; input is sanitized, output escaped, SQL
  prepared, credentials never logged.
- **Docs change in the same PR as the behaviour they describe.** That
  includes this file, `docs/architecture.md`, `readme.txt` and `docs/`.
  A doc that describes something the code no longer does is a bug.
