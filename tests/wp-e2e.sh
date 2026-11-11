#!/usr/bin/env bash
# Throwaway WordPress with wpbr-updater v1.0.0 pointed at the stub.
# After a yank, update-info and the update transient offer the previous
# version, and Plugin_Upgrader installs that older package.
# wp-content/debug.log must be empty for the run.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

PORT="${WPBR_E2E_PORT:-8766}"
export WPBR_SERVER_URL="http://127.0.0.1:${PORT}"
export WPBR_STUB_PUBLIC_URL="${WPBR_SERVER_URL}"
export WPBR_STUB_STATE="${ROOT}/build/e2e-state.json"
export WPBR_STUB_STORAGE="${ROOT}/build/e2e-storage"
export WPBR_RELEASE_USER="wpbr-release"
export WPBR_RELEASE_APP_PASSWORD="stub-only-not-a-secret"
export USE_STUB="false"
export WP_DB_HOST="${WP_DB_HOST:-127.0.0.1}"
export WP_DB_NAME="${WP_DB_NAME:-wpbr_e2e}"
export WP_DB_USER="${WP_DB_USER:-wp}"
export WP_DB_PASS="${WP_DB_PASS:-wp}"

mkdir -p "${WPBR_STUB_STORAGE}" "${ROOT}/build"
php tests/stub-call.php reset
php -S "127.0.0.1:${PORT}" -t "${ROOT}/tests/stub-server" "${ROOT}/tests/stub-server/server.php" \
  > "${ROOT}/build/e2e-stub.log" 2>&1 &
stub_pid=$!
trap 'kill "${stub_pid}" 2>/dev/null || true' EXIT

ready=0
for _ in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20; do
  if curl --silent --fail --max-time 1 "${WPBR_SERVER_URL}/health" >/dev/null; then
    ready=1
    break
  fi
  sleep 0.2
done
if [[ "${ready}" != "1" ]]; then
  echo "stub server did not start" >&2
  cat "${ROOT}/build/e2e-stub.log" >&2 || true
  exit 1
fi

work="${ROOT}/build/e2e-zips"
mkdir -p "${work}"
php tests/stub-call.php pack-plugin wpbr-rollback 1.0.0 "${work}/1.0.0.zip" >/dev/null
php tests/stub-call.php pack-plugin wpbr-rollback 1.1.0 "${work}/1.1.0.zip" yanked >/dev/null
old_id="$(php tests/stub-call.php upload wpbr-rollback 1.0.0 "${work}/1.0.0.zip")"
new_id="$(php tests/stub-call.php upload wpbr-rollback 1.1.0 "${work}/1.1.0.zip")"
php tests/stub-call.php publish "${old_id}" >/dev/null
php tests/stub-call.php publish "${new_id}" >/dev/null
export RELEASE_ID="${new_id}"
export WPBR_YANK_REASON="e2e rollback"
bash "${ROOT}/scripts/yank.sh"
export WPBR_OLD_ID="${old_id}"
export WPBR_NEW_ID="${new_id}"
WPBR_OLD_SHA="$(php tests/stub-call.php sha "${work}/1.0.0.zip")"
export WPBR_OLD_SHA
unset WPBR_RELEASE_APP_PASSWORD

if [[ -n "${GITHUB_TOKEN:-}" ]]; then
  printf '::add-mask::%s\n' "${GITHUB_TOKEN}"
  composer config --global github-oauth.github.com "${GITHUB_TOKEN}"
fi

cli="${ROOT}/build/wp-cli-2.12.0.phar"
if [[ ! -f "${cli}" ]]; then
  curl -fsSL -o "${cli}" https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar
fi
echo "ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c  ${cli}" | sha256sum -c -

wp_root="${ROOT}/build/wordpress"
wp() {
  php "${cli}" --path="${wp_root}" "$@"
}

if [[ ! -f "${wp_root}/wp-load.php" ]]; then
  php "${cli}" core download --path="${wp_root}" --version=7.1.4
fi
if [[ ! -f "${wp_root}/wp-config.php" ]]; then
  wp config create \
    --dbname="${WP_DB_NAME}" \
    --dbuser="${WP_DB_USER}" \
    --dbpass="${WP_DB_PASS}" \
    --dbhost="${WP_DB_HOST}" \
    --skip-check
  wp config set WP_DEBUG true --raw
  wp config set WP_DEBUG_LOG true --raw
  wp config set WP_DEBUG_DISPLAY false --raw
  wp config set FS_METHOD direct
fi
if wp core is-installed >/dev/null 2>&1; then
  wp db reset --yes >/dev/null
fi
wp core install \
  --url="http://127.0.0.1:8095" \
  --title="WPBR e2e" \
  --admin_user="admin" \
  --admin_password="admin" \
  --admin_email="admin@example.com" \
  --skip-email >/dev/null

plugin="${wp_root}/wp-content/plugins/wpbr-rollback"
rm -rf "${plugin}"
mkdir -p "${plugin}"
cat > "${plugin}/composer.json" <<'JSON'
{
    "name": "wpbrewer/wpbr-rollback-fixture",
    "type": "wordpress-plugin",
    "require": {
        "php": ">=7.4",
        "wpbrewer/wpbr-updater": "1.0.0"
    },
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/WPBrewer/wpbr-updater"
        }
    ],
    "config": {
        "sort-packages": true,
        "allow-plugins": true
    }
}
JSON
composer update --no-dev --no-interaction --no-progress --working-dir="${plugin}"
# PHP variables stay inside the single quotes on purpose.
# shellcheck disable=SC2016
installed="$(php -r '$j=json_decode((string) file_get_contents($argv[1]), true); foreach ($j["packages"] as $p) { if ($p["name"] === "wpbrewer/wpbr-updater") { echo $p["version"]; } }' "${plugin}/vendor/composer/installed.json")"
if [[ "${installed}" != "1.0.0" && "${installed}" != "v1.0.0" ]]; then
  echo "composer installed wpbr-updater ${installed}, expected 1.0.0" >&2
  exit 1
fi
if ! grep -q "const VERSION = '1.0.0';" "${plugin}/vendor/wpbrewer/wpbr-updater/src/Updater.php"; then
  echo "installed updater is not Updater::VERSION 1.0.0" >&2
  exit 1
fi

cat > "${plugin}/wpbr-rollback.php" <<PHP
<?php
/**
 * Plugin Name: WPBR Rollback
 * Version: 1.1.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: WPBrewer
 * License: GPL-3.0-or-later
 */

require __DIR__ . '/vendor/autoload.php';

\\WPBrewer\\Updater\\Updater::register([
    'file' => __FILE__,
    'slug' => 'wpbr-rollback',
    'server' => '${WPBR_SERVER_URL}',
    'version' => '1.1.0',
    'license_option' => 'wpbr_rollback_license',
]);
PHP

: > "${wp_root}/wp-content/debug.log"
export GIT_TERMINAL_PROMPT=0
php "${ROOT}/tests/wp-e2e-run.php" "${wp_root}"
