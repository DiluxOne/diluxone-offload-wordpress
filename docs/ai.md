# AI in this project

How AI is used to build and review the plugin, and the rules for anyone who contributes with AI. Coding agents read the short, enforceable version in [`AGENTS.md`](../AGENTS.md).

## How the plugin is built

The maintainer writes most changes with Claude Code, reads every diff, runs the tests and signs the commit. The rules an agent could break by mistake are enforced by CI and branch protection, so `AGENTS.md` is guidance and the checks are the guarantee. Every pull request carries one sober line saying how it was made, for example `🤖 AI-assisted · Claude Opus 5.5 (Anthropic)`; that line lands in the commit on `main`.

## The review on every pull request

Every pull request from a branch of this repository is reviewed by Claude, through the shared [`claude-review`](https://github.com/DiluxOne/.github/blob/main/.github/workflows/claude-review.yml) workflow in `DiluxOne/.github`. It runs after the conventions and the fast quality gates pass, so it never reviews code that does not build.

- **What it reads:** the organisation's review profiles ([`general.md`](https://github.com/DiluxOne/.github/blob/main/review-profiles/general.md) and [`plugin-wp.md`](https://github.com/DiluxOne/.github/blob/main/review-profiles/plugin-wp.md), with the lessons of the wordpress.org review), this repository's [`docs/architecture.md`](architecture.md) and [`AGENTS.md`](../AGENTS.md), and the diff.
- **What it does:** leaves one inline comment per blocker or major problem (minor ones stay in the summary), labels the pull request `risk:low|medium|high` and `complexity:low|medium|high`, and writes one summary comment with what the run cost. The check fails when it finds a blocking problem.
- **When it runs:** the whole change on the first push; on later pushes only what changed since its last look, with its earlier findings in hand. Editing the title or description does not trigger a review. After five automatic reviews on one pull request the last verdict stands, with auto-merge off, until the `review:full` label asks for another.
- **What it cannot do:** lower the risk that [`.github/review-policy.yml`](../.github/review-policy.yml) sets from the changed paths (the stream wrapper, crypto, settings, database, providers, templates, the logger and the main file are always high risk), push code, or merge.
- **Who posts:** the `dilux-bot` GitHub App of the DiluxOne organisation. Mention `@dilux-bot` in a thread or in the conversation and it answers there.
- **Model:** chosen by risk, from the policy files. A change that touches only low-risk paths (docs, tests, lockfiles) gets the light model at low effort (Claude Sonnet 5); anything else the strong one (Claude Opus 5.5). Every review setting lives in the organisation's default policy and in this repository's [`.github/review-policy.yml`](../.github/review-policy.yml) (model and effort per level, budget per run, auto-merge), read from `main` so a change cannot pick its own reviewer. It is paid per use through the organisation's Anthropic API key.

Pull requests from forks are not reviewed automatically: the review runs with the organisation's keys, and no code from outside the repository ever runs with them.

## What merges on its own

A pull request merges without a human only when all of these hold: the policy has `auto-merge` on, the changed paths are all low risk (docs, tests, translations and similar), the review rated it low risk and low complexity without blocking, and the author is trusted. GitHub then squash-merges it once every required check is green and every review conversation is resolved. Everything else is merged by the maintainer.

## What learns over time

Every Monday [a job in `DiluxOne/.github`](https://github.com/DiluxOne/.github/blob/main/.github/workflows/review-learnings.yml) reads the reviews of the organisation and opens pull requests a human approves: new review rules from lessons that repeated, and, for this repository, changes to its `.github/review-policy.yml` (a path that behaved as low risk enough times joins the safe list; a safe path that produced a revert leaves it) or its `AGENTS.md`. Every proposal says what changes, why, with the pull requests as evidence, and what starts happening if approved.

## Issues

When an issue opens, Claude classifies it from [`docs/roadmap.md`](roadmap.md), the README and `readme.txt`: a bug to reproduce, a report missing information, something that works as documented, a feature of the paid service, a feature request, a usage question, a duplicate or a vulnerability posted in public. It applies one label and posts one reply; it never closes an issue, never promises a fix and never gives a date. A `needs-info` issue nobody answers in 14 days is closed with a note.

A bug report with enough detail gets a reproduction attempt: Claude writes one unit test that fails if the bug exists, and the workflow runs it. If it fails, the bug is `bug:confirmed` and a draft pull request carries the test as evidence and as the starting point of the fix. If it passes, the issue is `could-not-reproduce` and the reporter is asked what would settle it. At most five attempts a day; the test can only be written under `tests/Unit/Repro/`, it runs on a runner with no secrets, and the real-storage suite does not run on drafts, which the bot's pull requests are. Marking one ready runs the suite with the Azure key, so read the test the bot wrote before you do.

## What is never automated

- **Releases.** Only the maintainer pushes a release tag.
- **Merging anything that is not low risk.** A human decides.
- **Changing the review rules.** Every change goes through a pull request the maintainer approves.
- **Closing a bug or shipping a fix.** The triage labels and asks; a person decides.

## Rules for contributing with AI

Use any tool you like. These rules apply the moment you open a pull request, whether or not you read them.

1. **You sign the commit, you own the code.** Whoever or whatever wrote it, a regression traced to your commit is yours to fix. "The AI wrote it" is not a defence.
2. **You read what you commit**, line by line. If you do not understand a generated chunk, do not push it.
3. **The tests pass because the test runner says so**, not because the model said so. Run `make check` before pushing.
4. **No secrets into AI services.** No `wp-config.php`, storage keys, SVN passwords or database dumps. If you slip, rotate the secret at once.
5. **No prompt injection** in commit messages, comments, code or docs: nothing that tries to steer a reviewer's tooling. A pull request that does this is rejected on sight.
6. **Verify what the model invents.** A WordPress function, an Azure API or a PHPStan rule that does not exist usually fails the build; the rest reaches production. Check the upstream docs.
7. **Same standards as hand-written code.** The linters do not care who typed it. If CI fails, fix the code, not the rule.
8. **Say that AI was involved**, in one line at the end of the description: `🤖 AI-assisted · <model> (<maker>)`, or `AI-generated` when the model wrote it and a person reviewed it. No apologies, no narration, no product advertising: CI rejects "Generated with …" footers, and rejects `Claude-Session:` trailers, which point at private conversations.
9. **One good pull request beats twenty speculative ones.** Pick a real bug, fix it well.

When an AI-assisted change causes a regression or a wordpress.org review failure: roll forward with a fix, open an issue saying why the checks did not catch it, and strengthen the gate that should have. The fix for a bad generated query is a stricter taint rule, not a ban on the tool.
