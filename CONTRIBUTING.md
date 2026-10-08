# Contributing to DiluxOne Offload

Thanks for helping. How a change reaches any DiluxOne repository (start from an accepted issue, branch names, commit and pull request titles, the description, the review, what CI checks, AI-assisted work) is in the organisation's [contributing guide](https://github.com/DiluxOne/.github/blob/main/CONTRIBUTING.md), and for an AI agent in [`AGENTS.md`](AGENTS.md). This page adds only what is specific to the plugin.

## Questions, bugs and ideas

- **Using the plugin** (how do I…?, my upload does not work): the [wordpress.org support forum](https://wordpress.org/support/plugin/diluxone-offload/), where answers stay public for the next person.
- **A bug or a feature request:** open a [new issue](https://github.com/DiluxOne/diluxone-offload-wordpress/issues/new/choose) with the matching form. What is free, paid, planned and not planned is in [`docs/roadmap.md`](docs/roadmap.md).
- **A security vulnerability:** privately, as the organisation's [security policy](https://github.com/DiluxOne/.github/blob/main/SECURITY.md) explains, never in a public issue.

## Before the pull request

Make the change with its tests at every layer it touches (unit, integration, end-to-end, the real-storage suites on a single site and on a network, and the listing screenshots when a screen changes; see [`docs/testing-and-quality.md`](docs/testing-and-quality.md)), and update any doc that describes what you changed. A change a user notices adds one bullet to the newest `= X.Y.Z =` entry of `readme.txt`, under its `Unreleased.` line; leave that line alone ([`docs/release.md`](docs/release.md)).

Then run `make pre-pr` (needs `make env` and `make env-multisite`): `make check` (PHPCS, PHPStan, Psalm, unit tests), the string extraction CI fails on any warning of (`make i18n-check`), the unit tests on PHP 7.4, the docs check (links, retired names), the integration and end-to-end suites, Plugin Check in strict mode, the kind's review rules (`make review-rules`) and the local review (`make review-local`, the organisation's `dx check`). The unit tests on PHP 8.0 to 8.5, the real-storage suites against cloud accounts and CodeQL run only in CI.

## Forks and the real-storage suites

One more check, the **real-storage suite** ([`tests-real-azure.yml`](.github/workflows/tests-real-azure.yml)), drives every plugin screen as a user, on a single site and on a multisite network, against a real Azure storage account, and verifies every transfer byte for byte. It needs the repository's Azure credentials, so it runs only on pull requests from branches of this repository that change code (like the other slow suites; see [`docs/testing-and-quality.md`](docs/testing-and-quality.md), "What runs when"), on every push to `main`, and by hand. **It does not run on pull requests from forks**, nor does the Claude review: no code from outside the repository ever runs with the keys. If you contribute from a fork, the other checks tell you what they can, the maintainer reviews, and the suite runs on `main` after the merge; if it fails there, a follow-up pull request fixes it. Its S3 counterpart ([`tests-real-s3.yml`](.github/workflows/tests-real-s3.yml)) runs the same journeys against an S3-compatible server started inside the job, with no secret, so it runs on forks too (`make s3-up && make test-real REAL_PROVIDER=s3` locally). The same workflow runs the journeys against Cloudflare R2, Backblaze B2 and Google Cloud Storage with the repository's secrets for each, under the Azure suite's rules: not on forks, not on drafts.

Pull requests Dependabot opens are treated like forks for the suites that need keys: GitHub runs them with Dependabot's own secrets, which hold none of the storage keys, and a package version that was just downloaded is code from outside the repository. They get every other check, the S3 journeys against the server started in CI included, and the keyed suites run on `main` after the merge: the push of a commit Dependabot authored always runs them, since a skipped check would otherwise count as the tree already tested.

Maintainers run the same suite locally with `make test-real` (after `make env` and `make env-multisite`, with `AZURE_E2E_ACCOUNT` / `AZURE_E2E_KEY` in the environment or in a git-ignored `.env.e2e`). Each run creates and deletes its own container.

## Coding rules the linters cannot express

- **PHP 7.4 and WordPress 5.1** are the minimums. No syntax or function from later versions without a fallback.
- **No Composer dependencies at runtime.** `composer install` brings dev tooling only; `vendor/` never ships.
- **Every user-facing string** goes through a translation function with the text domain `diluxone-offload`, with a `/* translators: */` comment on the line right before any `sprintf()` placeholder.
- **Input sanitised, output escaped, SQL prepared.** Psalm and PHPCS catch the obvious cases; you catch the rest.
- **Never log a credential**, even with debug logging on.
- **No local fallback.** When the cloud is down an upload fails; nothing is written to `uploads/` instead.
- **Every failure path of a provider call cleans up.** A non-2xx status, a transport error and a `200` with an error body all run the same cleanup (the `on_failure` callback, AbortMultipartUpload), including a commit run later through `curl_multi`; nothing is left stored and billed.

The full list, with the architecture and the review priorities, is in [`docs/architecture.md`](docs/architecture.md).

## Versions and releases

Nobody types a version: it is computed from the `type:*` labels the review sets on merged pull requests, and every push to `main` publishes a development build, `<next>-dev.<N>`, as the one **Development build** pre-release in [Releases](https://github.com/DiluxOne/diluxone-offload-wordpress/releases). Never bump the version or remove the `Unreleased.` line in your pull request. The whole flow, with who does what: [`docs/release.md`](docs/release.md).

## Licence

Your contributions are licensed under the [GPL-2.0-or-later](LICENSE).
