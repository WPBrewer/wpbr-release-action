# Release, publish, yank, roll back, pin

Version **0.2.0**. This action builds a plugin zip and uploads it as a **draft**. It can also **yank** a release. It never publishes. Publishing is a button on the license server's WPBR releases screen (an admin POST with a nonce), not a REST route.

Customers are offered a zip only after that Publish click, and only while the release stays published. A yanked release is never offered again.

## Secrets

Set these on the **plugin repository**, not in this public repository.

| Secret | Value |
| --- | --- |
| `WPBR_RELEASE_USER` | WordPress login of the service user |
| `WPBR_RELEASE_APP_PASSWORD` | Application password for that user |
| `WPBR_SERVER_URL` | Server origin only, for example `https://licenses.example.com` |

The service user needs the capability **`manage_wpbr_releases`**. `manage_options` is not the capability the server checks. A customer token, instance token, or Bearer token is rejected with **401**.

The workflows mask all three secrets and do not print them.

## Release

Push a `vX.Y.Z` tag. The `v` is not part of the header version. The plugin header `Version:` must be `X.Y.Z`.

1. `php -l`, then the caller's tests, then the caller's lint. A failure stops the job before Composer, the zip, the GitHub Release, and the upload.
2. Read `requires_wp`, `requires_php`, `tested_wp`, and the changelog from the plugin header, `readme.txt`, `CHANGELOG.md`, or the workflow inputs. A missing field fails the job before the upload.
3. `composer install --no-dev`, and Strauss when `extra.strauss` is set.
4. Build `{slug}-{version}.zip` from `.distignore`. The zip root is `{slug}/`.
5. sha256 of that zip.
6. GitHub Release `vX.Y.Z` with the zip attached, unless `skip-github-release` is true.
7. `POST {WPBR_SERVER_URL}/wp-json/wpbr-license/v1/releases` as multipart parts `metadata` (`application/json`) and `zip`, with the application password. HTTP 200 and `status: draft` is success. The log line is `draft id=<number>`.

Until Publish, `update-info` does not offer the zip. `GET /info/{slug}` does not show a draft version.

## Publish

Open the WPBR releases screen in the license server wp-admin and publish that draft. There is no REST publish route. After Publish, a client on an older version gets that zip with `allow_downgrade` false. The sha256 in `update-info` is the zip sha256.

## Yank

Yank is how you pull a published release. The previous published version is what clients get instead.

The id is the numeric release id. There is no lookup by slug and version. Read it from:

- the release job log, `draft id=<number>`, or
- the numeric id on that release's row on the WPBR releases screen.

```yaml
name: Yank release
on:
  workflow_dispatch:
    inputs:
      release-id:
        description: Numeric release id.
        required: true
        type: string
      reason:
        description: Optional. Not shown to customers.
        required: false
        type: string
        default: ""

permissions:
  contents: read

jobs:
  yank:
    uses: WPBrewer/wpbr-release-action/.github/workflows/yank.yml@v0.2.0
    with:
      release-id: ${{ inputs.release-id }}
      reason: ${{ inputs.reason }}
    secrets:
      WPBR_RELEASE_USER: ${{ secrets.WPBR_RELEASE_USER }}
      WPBR_RELEASE_APP_PASSWORD: ${{ secrets.WPBR_RELEASE_APP_PASSWORD }}
      WPBR_SERVER_URL: ${{ secrets.WPBR_SERVER_URL }}
```

Until the `v0.2.0` tag exists, pin the merge commit SHA instead of `@v0.2.0`.

`POST /wp-json/wpbr-license/v1/releases/{id}/yank` accepts an empty body, `{}`, or `{"reason":"..."}`. Success is HTTP 200 `{"id":42,"status":"yanked"}`. Yanking an already-yanked release returns that same 200. The log line is `yanked id=<number> status=yanked`.

Yanking a **draft** is HTTP 409 `invalid_request`. Publish it, or delete it on the server. Do not yank a draft.

## Roll back

Rolling back is a yank of the bad release. There is no second endpoint.

- The yanked zip is never returned as `package`.
- Clients are offered the newest release that is still **published** (drafts and yanked rows are ignored).
- If that offer is older than what the site has installed (the usual case: the site is on the yanked version, or anything newer), `allow_downgrade` is true. `wpbr-updater` then puts that zip in the update transient, and WordPress' upgrader is allowed to install it.
- If a newer published release still exists, that newer one is offered with `allow_downgrade` false.
- If the yanked release was the only published one, `update_available` is false. Nothing is offered.

Ship the fix as a **new** version number. Do not upload the yanked version again.

## Pin

`pinned_version` is set on the license, on the server. This action does not set it.

The offer is the newest published stable release whose version is `<=` the pin. A pin below the installed version is offered with `allow_downgrade` true. A pin equal to the installed version offers nothing. A pin that is not a valid version offers nothing.

## Never reuse a version

`product_slug` + `version` is tombstoned the moment `POST /releases` accepts it. A later upload of that pair is HTTP 409 `invalid_request`, including:

- the same zip (identical sha256) or a different zip,
- after a yank,
- after the row is deleted on the server.

The draft staying unpublished does not free the version. A 409 on a retry means the first create already landed. Publish that draft, or leave it, and ship the next number.

## Troubleshooting

| What you see | What it means |
| --- | --- |
| 401 `unauthorized` | The application password was rejected, or a customer / instance / Bearer token was sent. Yank and upload both want the service user's application password. |
| 403 `forbidden` | The WordPress user authenticated and does not have `manage_wpbr_releases`. `manage_options` does not satisfy that check. |
| 400 `invalid_request` | The body does not match the contract: metadata fields, sha256 vs the zip, multipart part names, or a yank body other than empty / `{}` / `{"reason":"..."}`. |
| 409 `invalid_request` on upload | That slug+version was uploaded before. Nothing new was published. Use a new version. |
| 409 `invalid_request` on yank | The id is a draft. Publish it or delete it. Yank does not apply to drafts. |
| 404 `release_not_found` | No release with that numeric id. Use `draft id=` from the upload log, or the id on the WPBR releases screen. |
| 403 `license_expired` on `update-info` | The license is expired. No package is returned. The plugin keeps running; updates stop. |
| 200 and `update_available` false | Nothing to offer: no published release, the client is already on the offered version, the license is revoked, the pin excludes every published release, or the pin is invalid. `package`, `sha256`, and `allow_downgrade` are omitted. |
| GitHub Release exists, upload then failed | If the POST never returned 200, the server did not consume the version. Delete that GitHub Release and rerun. If it returned 200, the version is consumed: do not reuse it. |
| `use-stub: true` | Self-test only. It ignores the three secrets and talks to the in-repo stub. Do not enable it on a real release. |

`update-info` is HTTP 200 for an active or revoked license. It is HTTP 403 `license_expired` with no package when the license is expired. `update_available` is true only when a published stable release (respecting `pinned_version`, ignoring drafts and yanked rows) differs from the client's version and the license is active.
