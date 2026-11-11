# wpbr-release-action

Reusable GitHub Actions workflow that builds a WordPress plugin zip and uploads it to the WPBrewer license server as a **draft**.

Version **0.1.0**. This repository is not tagged yet. Callers should pin the commit SHA until `v0.1.0` exists. GPL-3.0-or-later for these files. The `LICENSE` file is the GPL-3.0 text.

Customers are not offered the zip until someone clicks **Publish** in the license server admin. The workflow never publishes.

## Caller

```yaml
name: Release
on:
  push:
    tags:
      - "v[0-9]+.[0-9]+.[0-9]+"

permissions:
  contents: write

jobs:
  release:
    uses: WPBrewer/wpbr-release-action/.github/workflows/release.yml@v0.1.0
    with:
      slug: wpbr-example
      php-version: "8.3"
      package-directory: .
      test-command: composer test
      lint-command: composer lint
    secrets:
      WPBR_RELEASE_USER: ${{ secrets.WPBR_RELEASE_USER }}
      WPBR_RELEASE_APP_PASSWORD: ${{ secrets.WPBR_RELEASE_APP_PASSWORD }}
      WPBR_SERVER_URL: ${{ secrets.WPBR_SERVER_URL }}
```

Until the `v0.1.0` tag exists, replace `@v0.1.0` with the merge commit SHA.

### Secrets (in the plugin repository, not here)

| Secret | Value |
| --- | --- |
| `WPBR_RELEASE_USER` | WordPress login that owns the application password |
| `WPBR_RELEASE_APP_PASSWORD` | Application password for a user who can `manage_wpbr_releases` |
| `WPBR_SERVER_URL` | Server origin only, for example `https://licenses.example.com` |

The workflow masks all three. It does not print them. Do not put them in this public repository.

### What a tag does

On `vX.Y.Z` (the `v` is not part of the header version):

1. `php -l` on every PHP file in the package directory except `vendor/`, `vendor-prefixed/`, and `node_modules/`.
2. `test-command`, then `lint-command`. Both run before Composer, the zip, the GitHub Release, and the upload. A non-zero exit stops the job.
3. The main file `{slug}.php` header `Version:` must equal `X.Y.Z`. `requires_wp` is that file's `Requires at least`, or the `requires-wp` input. `requires_php` is `Requires PHP`, or the `requires-php` input. `tested_wp` is `Tested up to` in `readme.txt`, or the `tested-wp` input. The changelog is the `changelog` input, or the `CHANGELOG.md` / `readme.txt` section for this version. A missing field fails the job before the upload.
4. If `composer.json` exists, `composer install --no-dev`. If `extra.strauss` is set, dev dependencies are installed first so `vendor/bin/strauss` can run, then `composer install --no-dev` runs again.
5. `{slug}-{version}.zip` is built from `.distignore` (gitignore-style, last match wins). `.git` is always left out. The zip root is `{slug}/`.
6. sha256 of that zip.
7. GitHub Release `vX.Y.Z` with the zip attached, unless `skip-github-release` is true.
8. `POST {WPBR_SERVER_URL}/wp-json/wpbr-license/v1/releases` as multipart with two parts: `metadata` (`Content-Type: application/json`) and `zip` (the package). Basic auth is the WordPress application password. HTTP 200 must return `status: draft` and the same sha256. HTTP 400, 401, 403, and 409 fail the job. The log shows the server error `code` and does not print the secrets.

`test-command` is responsible for its own dev dependencies (`composer install && vendor/bin/phpunit`). This workflow's Composer install is the production build, and it happens after tests.

Optional inputs `changelog`, `requires-wp`, `requires-php`, and `tested-wp` override the files. Leave them empty to use the header, `readme.txt`, and the changelog section.

### What you should see

- The job log shows `draft id=… slug=… version=… sha256=… status=draft`.
- The license server has a **draft**. `update-info` does not offer it.
- After **Publish** in the server admin, clients on older versions get that zip. The sha256 in `update-info` is the zip sha256.
- Uploading the same `{product_slug, version}` again returns **409**. The version stays used even if the row is later yanked or deleted. Nothing new is published.
- A header/tag mismatch, a failing test, or a failing lint stops the job before the upload. The server is not called.

If step 7 created a GitHub Release and step 8 then failed, the server did not consume the version. Delete that GitHub Release and rerun. If step 8 returned 200, the version is consumed: do not reuse it. Publish, or leave the draft. A 409 on a later attempt means the first create already landed. 401 means the application password was rejected. 403 means the user lacks `manage_wpbr_releases`. 400 means the body did not match the contract.

`skip-github-release` still uploads the draft. `use-stub` is only for this repository's self-test. It ignores the three secrets and talks to the in-repo stub.

## Self-test

`tests/self-test.sh` runs the same `scripts/release.sh` the workflow runs, against `tests/stub-server`. The stub implements the da084ce `/releases` and `/update-info` behavior described in `tests/stub-server/schema/`. It is not the production license server. Its Basic-auth password is a fixture (`stub-only-not-a-secret`) and is not a credential.

```bash
bash tests/self-test.sh
```

Needs PHP (curl, mbstring, zip), Composer, unzip, and `curl`.
