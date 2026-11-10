# Changelog

All notable changes to SSG Publish are documented here.

0.1.0 is an unpublished prototype. HTTP tests do not show that a live GitHub Actions workflow or a Cloudflare Pages, Netlify, or Vercel hook accepts a publish.

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
