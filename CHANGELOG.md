# Changelog

All notable changes to SSG Publish are documented here.

Nothing here is tagged. Packagist versions come from git tags, and this repository is not on Packagist until the maintainer submits it. GitHub Actions, Cloudflare Pages, Netlify, and Vercel have still not been called with real credentials. See `docs/E2E-REPORT.md` for the local hook publish that was verified against a real HTTP server.

## Unreleased

### Added

- A PHPUnit test that posts to a real local TLS server, without `Http::fake`, and checks the method, path, headers, and empty body.
- `docs/E2E-REPORT.md` and Control Panel screenshots under `docs/screenshots/` from a fresh Statamic 6.41 app publishing to a local HTTPS listener.
- `composer validate --strict` and `composer audit` in GitHub Actions. Composer is configured to refuse dependency updates that have a known security advisory.

### Changed

- Removed the `version` field from `composer.json`. Packagist takes the version from the git tag.
- The utility starts polling build status after a publish moves a target to queued or in progress, including when the page did not load in that state.

### Fixed

- The utility page did not start polling after Publish moved a GitHub target to queued or in progress, unless the page had loaded in that state. Polling now follows that state.

## 0.1.0 - 2026-11-05

### Added

- Control Panel utility at Utilities → SSG Publish, with a Publish button gated by the `access ssg_publish utility` permission.
- Dashboard widget with the same permission check, publish action, and a link to the utility.
- Deploy targets in `config/ssg-publish.php`: GitHub Actions `workflow_dispatch`, plus deploy hooks for Cloudflare Pages, Netlify, Vercel, and a generic HTTPS hook.
- GitHub build status (queued, in progress, success, failure) with a link to the run, and hook status showing the last trigger time and response. Tokens and hook URLs stay on the server.
- A change list of entries, terms, and globals saved or deleted since the last successful publish, recorded from Statamic events.
- `php please ssg-publish:scheduled` for the Laravel scheduler: optional auto-publish when a future-dated entry comes due, and optional debounced auto-publish after saves.
- Free and Pro editions declared in `composer.json`. Free is the manual publish button and the first target. Pro adds build status, the change list, automatic publish, and multiple targets.

### Notes

- External calls to GitHub and deploy hooks are not verified against live accounts. Tests use HTTP fakes only.
- Composer package name is `big-city-software/statamic-ssg-publish`. Copyright and seller: Segment Holdings LLC. Support: hello@segment.holdings. Free is manual publish. Pro is $39.
