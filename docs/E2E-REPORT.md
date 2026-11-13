# End-to-end report

Date: 2026-11-13. This is a record of what was actually run, not a claim that every driver has been accepted by its vendor.

## What was real

A fresh Statamic app was created with `composer create-project statamic/statamic`. It resolved `statamic/cms` v6.41.0 and `laravel/framework` v13.42.0. `statamic/ssg` 4.2.2 was installed with Composer. SSG Publish was installed from a path repository pointing at this checkout (`dev-cursor/marketplace-e2e-release-78b5`), not from Packagist. `php please statamic:install` discovered the addon and published `resources/dist/build` to `public/vendor/statamic-ssg-publish/build`. The config was published with `php please vendor:publish --tag=ssg-publish-config`.

The app ran at `http://127.0.0.1:8000` with `php artisan serve`. A super user signed in through the Control Panel login form in a browser. Publish was triggered by clicking the Control Panel button, which posts to `/cp/utilities/ssg-publish/publish`. Nothing in that path used `Http::fake`.

The target was a generic `hook` driver aimed at `https://127.0.0.1:8443`. A Python process listened there with TLS. Its certificate was signed by a local CA that was added to the machine trust store with `update-ca-certificates`. The addon uses Laravel's HTTP client with certificate verification left on. A request that did not trust the CA failed before the listener was trusted. After the CA was installed, the same client got HTTP 200.

### Request the listener recorded

Free edition, header **Publish** on the Production target. The listener stored one request and nothing else:

| | |
| --- | --- |
| Method | `POST` |
| Path | `/deploy/secret-path` |
| Body | empty |
| `Host` | `127.0.0.1:8443` |
| `User-Agent` | `ssg-publish` |
| `Accept` | `application/json` |
| `Content-Type` | `text/plain` |
| `Content-Length` | `0` |
| `Authorization` | absent |

That matches the hook client: `POST`, `User-Agent: ssg-publish`, `Accept: application/json`, an empty `text/plain` body, redirects disabled. The second target's URL (`/deploy/second-secret`) was not called. The page showed "Deploy hook accepted the deploy hook." and kept build status hidden ("Build status is part of SSG Publish Pro."). The Preview button stayed disabled.

Pro was then selected in `config/statamic/editions.php` (`big-city-software/statamic-ssg-publish` => `pro`). After a reload, Production showed **Accepted**, last trigger `2026-11-13T17:01:05+00:00`, HTTP 200, and the response excerpt `{"ok":true,"message":"accepted"}`. The hook URL was not in the page.

The Home entry title was changed in the Control Panel to "Home updated for publish" and saved. The change list showed one row: Saved, "Home updated for publish", pages.

Pro, **Publish** on the Preview card. The listener stored a second request, same method, headers, and empty body, path `/deploy/second-secret`. Preview changed to Accepted with HTTP 200 and the same excerpt. The change list changed to "Nothing has changed since the last successful publish."

Switching the edition back to `free` and hard-refreshing hid the Accepted state again, disabled Preview's Publish button, and replaced the change list with the free-edition notice. The stored status was still on disk. The page did not show it.

### Scheduled auto-publish

`SSG_PUBLISH_AUTO_SCHEDULED=true` and `auto_targets` set to `production` only, so a scheduled run would not also call Preview.

`php please schedule:list` included `* * * * * php artisan ssg-publish:scheduled`.

The first `php please ssg-publish:scheduled` printed "Nothing is due." and the listener received nothing. That is the baseline cursor.

A dated `news` entry was saved with its date 15 seconds ahead. Twenty seconds later the command printed `Production: Deploy hook accepted the deploy hook.` The listener received one POST to `/deploy/secret-path` with the same headers and an empty body. Preview was not called.

### Debounced save

`SSG_PUBLISH_AUTO_ON_SAVE=true` and `SSG_PUBLISH_DEBOUNCE=5`. Saving the Home entry wrote a pending publish five seconds out. Running the command immediately printed "Nothing is due." and sent nothing. Six seconds later it sent one POST to `/deploy/secret-path`. Running it again printed "Nothing is due." and did not send a second request.

### Free edition does not auto-publish

With the edition set back to `free`, the scheduled cursor moved two hours back, and a published dated entry dated 30 minutes ago was saved. `php please ssg-publish:scheduled` printed "Scheduled auto-publish requires SSG Publish Pro." The listener received nothing.

## What is still faked

These were not called, and this report does not say they work:

- GitHub Actions `workflow_dispatch` and the workflow-run status API
- Cloudflare Pages deploy hooks on `api.cloudflare.com`
- Netlify build hooks
- Vercel deploy hooks

There are no credentials for those accounts in this environment. The PHPUnit tests that mention them still use `Http::fake`. The generic hook driver is the same class the Cloudflare, Netlify, and Vercel drivers use (`HookTarget`), so those three differ by label and by the URL you configure. That is not the same thing as those vendors accepting a request.

GitHub polling after a click is also untested against GitHub. The utility page now starts its 15 second status poll when a target becomes queued or in progress after Publish, including when the page did not load in that state. A hook finishes immediately, so this Control Panel run never sat in the queued state.

`statamic/ssg` was installed. `php please ssg:generate` was not run. The addon does not generate HTML. It only asks a hook or a workflow to build.

## Screenshots

Committed under `docs/screenshots/` from that Control Panel session:

- `free-edition.png` — Publish enabled on the first target, the second target locked, build status and the change list gated
- `free-publish-accepted.png` — the success message after the real free-edition publish, with status still hidden
- `pro-status-and-change-list.png` — Accepted, HTTP 200, response excerpt, and the saved entry
- `pro-second-target-accepted.png` — Preview accepted and the change list cleared
- `dashboard-widget.png` — the dashboard widget Publish button on the free edition
