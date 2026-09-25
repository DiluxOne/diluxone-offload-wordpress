# Security Policy

## Reporting a vulnerability

**Please do not open a public GitHub issue or wp.org forum thread to report a security vulnerability.** Public disclosure before a fix is available puts every site running this plugin at risk.

Instead, report the issue privately using **GitHub Security Advisories**:

🔗 **[Open a private security advisory](https://github.com/DiluxOne/diluxone-offload-wordpress/security/advisories/new)**

This creates a confidential workspace inside the repository where the maintainers and you can discuss the issue, coordinate a fix, and agree on a disclosure timeline. Nothing is public until we both decide it's ready.

## What to include in your report

To help us triage and reproduce the issue quickly, please include:

- **A clear description** of the vulnerability and its impact.
- **Steps to reproduce** — the smaller the test case, the better.
- **Affected versions** of the plugin (and ideally PHP / WordPress versions you tested on).
- **Any proof-of-concept** code or payloads you used.
- **Your proposed severity** (low / medium / high / critical) and reasoning.
- **Suggested mitigation**, if you have one.

## What to expect

| Stage | Target time |
|-------|-------------|
| Initial response (we acknowledge the report) | within 72 hours |
| Triage and severity assessment | within 7 days |
| Fix or mitigation timeline | depends on severity — communicated after triage |
| Public disclosure (advisory + release) | coordinated with the reporter |

We aim to credit reporters in the published advisory unless you prefer to remain anonymous.

## Supported versions

Security fixes are released against the **latest published version** of the plugin. Older versions are not patched separately — please update to the latest release before reporting.

| Version | Supported |
|---------|-----------|
| Latest published on wp.org | ✅ |
| Older versions | ❌ |

## Out of scope

The following are **not** considered security vulnerabilities for the purposes of this policy (though we still welcome reports as regular issues):

- Issues that require physical access to the server.
- Issues that require an already-compromised WordPress admin account.
- Vulnerabilities in third-party services (Azure, etc.) — please report those to the respective vendor.
- Best-practice deviations without a concrete attack path.

Thanks for helping keep DiluxOne Offload and its users safe.
