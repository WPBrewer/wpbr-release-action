# Changelog

## 0.1.0

- Reusable `workflow_call` workflow. On a `vX.Y.Z` tag it runs `php -l`, the caller's tests, and the caller's lint before building, checks the plugin header `Version:`, runs `composer install --no-dev` and Strauss when `extra.strauss` is set, builds `{slug}-{version}.zip` from `.distignore`, and uploads that zip to `POST /wp-json/wpbr-license/v1/releases` as a draft. The body is multipart parts `metadata` (`application/json`) and `zip`. Success is HTTP 200 with `status: draft` and the same sha256. `requires_wp`, `requires_php`, `tested_wp`, and `changelog` come from the plugin header, `readme.txt`, the changelog section for that version, or workflow inputs. A missing field fails the job before the upload.
- GitHub Release creation is a separate step and can be skipped with `skip-github-release`.
- No tag is published from this repository by this change.
