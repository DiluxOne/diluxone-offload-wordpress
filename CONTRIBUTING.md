# Contributing to DiluxOne Offload

Thanks for helping. This page covers issues, pull requests and what CI enforces. The organisation's [contributing guide](https://github.com/DiluxOne/.github/blob/main/CONTRIBUTING.md) has the general rules; this one adds what is specific to the plugin.

## Bugs, ideas and questions

- **A bug or a feature request:** open a [new issue](https://github.com/DiluxOne/diluxone-offload-wordpress/issues/new/choose) with the matching template. Claude reads it first, labels it and replies once (it may ask for versions, steps or logs, or try to reproduce a bug with a unit test); the maintainer decides what happens next. What is free, paid, planned and not planned is in [`docs/roadmap.md`](docs/roadmap.md).
- **Using the plugin** (how do I…?, my upload does not work): the [wordpress.org support forum](https://wordpress.org/support/plugin/diluxone-offload/), where answers stay public for the next person.
- **A security vulnerability:** [SECURITY.md](SECURITY.md), never a public issue.

## Pull requests

1. Branch from `main`: in your fork if you are an outside contributor, in the repository if you are a maintainer. Name it `<type>/<kebab-case>`, for example `fix/sync-retry-count`.
2. Make the change with its tests, and update any doc that describes what you changed, `readme.txt` included.
3. Run `make check` (PHPCS, PHPStan, Psalm, unit tests). Integration, end-to-end, i18n and Plugin Check have their own targets; CI runs all of them.
4. Open the pull request and fill in the template: 📝 What changes and 💡 Why are required, 🧪 How I tested it and 📸 Screenshots help the review. The description becomes the commit body on `main`, word for word, so write it for the person who reads the history in a year: plain words, short paragraphs.
5. If AI took part, end the description with one line: `🤖 AI-assisted · <model> (<maker>)`. The rules for contributing with AI are in [`docs/ai.md`](docs/ai.md).

Pull requests are squash-merged: the title becomes the commit title on `main`, the description its body, and the branch's `Co-authored-by` trailers are kept. Nothing reaches `main` without a green pull request, maintainers included.

### Titles and commits

[Conventional Commits](https://www.conventionalcommits.org/): `<type>(<optional-scope>): <subject>`, with type one of `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `build`, `ci`, `chore`, `revert`, at most 100 characters, no trailing period. The same format applies to the pull request title and to every commit on the branch. Bodies are plain paragraphs, one line each, never hard-wrapped.

```
feat(provider): add S3 cloud storage provider
fix(admin): surface decrypt failures in the connection-health banner
```

### What CI enforces

The shared [`conventions`](https://github.com/DiluxOne/.github/blob/main/.github/workflows/conventions.yml) workflow fails a pull request when the branch name, the title or a commit breaks the format above, when a commit carries a `Claude-Session:` trailer, when "📝 What changes" or "💡 Why" is empty, when the description ends with a "Generated with …" footer, when a relative link in the docs is broken, or when a retired product name comes back.

Then the quality gates: syntax and unit tests on PHP 7.4 to 8.5, PHPCS with the WordPress Coding Standards, PHPStan level 8, Psalm taint analysis, i18n extraction, WordPress Plugin Check on the shipped tree, readme and version alignment, integration tests on a wp-env network and Playwright end-to-end tests with a fake cloud client. What each one catches, and how to run it: [`docs/testing-and-quality.md`](docs/testing-and-quality.md).

Every job that runs on a pull request is a required check on `main`, except CodeQL, which runs only when JavaScript changes.

### The review

Claude reviews every pull request from a branch of this repository, guided by [`docs/architecture.md`](docs/architecture.md), [`AGENTS.md`](AGENTS.md) and the organisation's WordPress review profile. It comments inline on blockers and majors, lists minor findings in its summary, labels the risk, the complexity and the type of the change (`type:*`, read from the diff; it corrects the title's type to match, and a `type:*` label a person sets wins), and checks that the description matches the code. Editing the title or description re-runs only the conventions and that check, never the suites; an edited description counts as unchecked until the next push. Fix the code and push, or answer in the thread mentioning `@dilux-bot`; every conversation must be resolved before merging. A change rated low risk and low complexity, on low-risk paths, from a trusted author, merges on its own once everything is green; everything else the maintainer merges. Details and the rules for AI-assisted work: [`docs/ai.md`](docs/ai.md).

### Forks and the real-storage suite

One more check, the **real-storage suite** ([`tests-real-azure.yml`](.github/workflows/tests-real-azure.yml)), drives every plugin screen as a user, on a single site and on a multisite network, against a real Azure storage account, and verifies every transfer byte for byte. It needs the repository's Azure credentials, so it runs only on pull requests from branches of this repository that change code (like the other slow suites; see [`docs/testing-and-quality.md`](docs/testing-and-quality.md), "What runs when"), on every push to `main`, and by hand. **It does not run on pull requests from forks**, nor does the Claude review: no code from outside the repository ever runs with the keys. If you contribute from a fork, the other checks tell you what they can, the maintainer reviews, and the suite runs on `main` after the merge; if it fails there, a follow-up pull request fixes it.

Maintainers run the same suite locally with `make test-real` (after `make env` and `make env-multisite`, with `AZURE_E2E_ACCOUNT` / `AZURE_E2E_KEY` in the environment or in a git-ignored `.env.e2e`). Each run creates and deletes its own container.

## Coding rules the linters cannot express

- **PHP 7.4 and WordPress 5.1** are the minimums. No syntax or function from later versions without a fallback.
- **No Composer dependencies at runtime.** `composer install` brings dev tooling only; `vendor/` never ships.
- **Every user-facing string** goes through a translation function with the text domain `diluxone-offload`, with a `/* translators: */` comment on the line right before any `sprintf()` placeholder.
- **Input sanitised, output escaped, SQL prepared.** Psalm and PHPCS catch the obvious cases; you catch the rest.
- **Never log a credential**, even with debug logging on.
- **No local fallback.** When the cloud is down an upload fails; nothing is written to `uploads/` instead.

The full list, with the architecture and the review priorities, is in [`docs/architecture.md`](docs/architecture.md).

## Versions and releases

Versions follow [Semantic Versioning](https://semver.org/). `main` carries `X.Y.Z-dev` between releases; never bump the version in a feature pull request. The release flow, for maintainers, is in [`docs/release.md`](docs/release.md).

## Code of Conduct and licence

By participating you agree to the organisation's [Code of Conduct](https://github.com/DiluxOne/.github/blob/main/CODE_OF_CONDUCT.md). Your contributions are licensed under the [GPL-2.0-or-later](LICENSE).
