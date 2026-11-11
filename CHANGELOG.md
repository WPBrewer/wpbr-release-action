# Changelog

## 0.2.0

- `workflow_dispatch` and `workflow_call` workflow that yanks a release by numeric id: `POST /wp-json/wpbr-license/v1/releases/{id}/yank` with the same application-password secrets as the release workflow. Optional `reason`. Success is HTTP 200 `{"id":…,"status":"yanked"}`. A repeated yank is 200. A draft is 409 `invalid_request`. The id is the number from the upload log (`draft id=`) or the WPBR releases screen. There is no slug+version lookup.
- Docs for release, publish, yank, roll back, and pin: `manage_wpbr_releases` (not `manage_options`), the never-reuse-a-version rule, and 401/403/400/409, `release_not_found`, a failed upload after a GitHub Release, and yanking a draft.
- The contract stub matches those rules. A yanked release is not offered. The previous published release is offered with `allow_downgrade` when the client is on the yanked version or anything newer. `pinned_version` caps the offer. An invalid pin offers nothing. Expired is 403 `license_expired`. Revoked is 200 with no package. A slug+version stays tombstoned after a yank or after the row is removed, including an identical sha256.
- No tag is published from this repository by this change.

## 0.1.0

- Reusable `workflow_call` workflow. On a `vX.Y.Z` tag it runs `php -l`, the caller's tests, and the caller's lint before building, checks the plugin header `Version:`, runs `composer install --no-dev` and Strauss when `extra.strauss` is set, builds `{slug}-{version}.zip` from `.distignore`, and uploads that zip to `POST /wp-json/wpbr-license/v1/releases` as a draft. The body is multipart parts `metadata` (`application/json`) and `zip`. Success is HTTP 200 with `status: draft` and the same sha256. `requires_wp`, `requires_php`, `tested_wp`, and `changelog` come from the plugin header, `readme.txt`, the changelog section for that version, or workflow inputs. A missing field fails the job before the upload.
- GitHub Release creation is a separate step and can be skipped with `skip-github-release`.
- No tag is published from this repository by this change.
