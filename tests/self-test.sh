#!/usr/bin/env bash
# Runs the release script against the local contract stub.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

export WPBR_SERVER_URL="http://127.0.0.1:8765"
export WPBR_STUB_PUBLIC_URL="${WPBR_SERVER_URL}"
export WPBR_STUB_STATE="${ROOT}/stub-state.json"
export WPBR_STUB_STORAGE="${ROOT}/stub-storage"
export WPBR_RELEASE_USER="wpbr-release"
export WPBR_RELEASE_APP_PASSWORD="stub-only-not-a-secret"
export SKIP_GITHUB_RELEASE="true"
export USE_STUB="false"
export GITHUB_REF_TYPE="branch"
export GITHUB_REF_NAME="self-test"
export INPUT_VERSION="v1.2.3"

mkdir -p "${WPBR_STUB_STORAGE}"
php tests/stub-call.php reset
php -S 127.0.0.1:8765 -t "${ROOT}/tests/stub-server" "${ROOT}/tests/stub-server/server.php" \
  > "${ROOT}/stub-server.log" 2>&1 &
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
  cat "${ROOT}/stub-server.log" >&2 || true
  exit 1
fi

run_release() {
  local phase_file="$1"
  set +e
  WPBR_PHASE_FILE="${phase_file}" bash "${ROOT}/scripts/release.sh"
  local code=$?
  set -e
  return "${code}"
}

assert_phases() {
  local phase_file="$1"
  shift
  local actual
  actual="$(paste -sd, "${phase_file}")"
  local expected
  expected="$(IFS=,; echo "$*")"
  if [[ "${actual}" != "${expected}" ]]; then
    echo "phases were ${actual}, expected ${expected}" >&2
    exit 1
  fi
}

echo "CASE happy"
php tests/stub-call.php reset
phase_file="$(mktemp)"
export SLUG="wpbr-dummy"
export PACKAGE_DIRECTORY="tests/dummy-plugin"
export TEST_COMMAND="php tests/run.php"
export LINT_COMMAND="php tests/lint.php"
if ! run_release "${phase_file}"; then
  echo "happy path failed" >&2
  exit 1
fi
assert_phases "${phase_file}" php-lint tests lint version composer zip upload
sha="$(php tests/stub-call.php sha "${ROOT}/build/wpbr-dummy-1.2.3.zip")"
php tests/stub-call.php expect-draft "${sha}" wpbr-dummy
php tests/stub-call.php expect-stored '{"changelog":"- Self-test fixture.","requires_wp":"6.5","requires_php":"7.4","tested_wp":"6.7"}'
php tests/stub-call.php reject-unauthorized
php tests/stub-call.php reject-bad-sha wpbr-dummy "${ROOT}/build/wpbr-dummy-1.2.3.zip"
php tests/stub-call.php reject-unknown-field wpbr-dummy "${ROOT}/build/wpbr-dummy-1.2.3.zip"
php tests/stub-call.php reject-wrong-part wpbr-dummy "${ROOT}/build/wpbr-dummy-1.2.3.zip"
php tests/stub-call.php reject-loose-fields wpbr-dummy "${ROOT}/build/wpbr-dummy-1.2.3.zip"
php tests/stub-call.php publish-and-expect "${sha}" wpbr-dummy

work="$(mktemp -d)"
unzip -q "${ROOT}/build/wpbr-dummy-1.2.3.zip" -d "${work}"
test -f "${work}/wpbr-dummy/wpbr-dummy.php"
test -f "${work}/wpbr-dummy/includes/class-dummy.php"
test -f "${work}/wpbr-dummy/keep-me.txt"
test -f "${work}/wpbr-dummy/CHANGELOG.md"
if [[ -e "${work}/wpbr-dummy/secret-not-for-dist.txt" || -e "${work}/wpbr-dummy/tests/run.php" || -e "${work}/wpbr-dummy/composer.json" || -d "${work}/wpbr-dummy/vendor" ]]; then
  echo "distignore leaked a file into the zip" >&2
  find "${work}" -type f >&2
  exit 1
fi
if ! grep -R -F -q "namespace WpbrDummy\\" "${work}/wpbr-dummy/vendor-prefixed"; then
  echo "Strauss prefix missing from the zip" >&2
  find "${work}" -type f >&2
  exit 1
fi
echo "zip respects .distignore and includes the Strauss prefix"

override="$(mktemp)"
WPBR_CHANGELOG='From input.' \
WPBR_REQUIRES_WP=6.4 \
WPBR_REQUIRES_PHP=8.2 \
WPBR_TESTED_WP=6.9 \
  php scripts/package.php metadata \
    --source "${ROOT}/tests/dummy-plugin" \
    --slug wpbr-dummy \
    --version 1.2.3 \
    --zip "${ROOT}/build/wpbr-dummy-1.2.3.zip" \
    --out "${override}"
php tests/stub-call.php expect-metadata-file "${override}" '{"changelog":"From input.","requires_wp":"6.4","requires_php":"8.2","tested_wp":"6.9"}'
readme_meta="$(mktemp)"
php scripts/package.php metadata \
  --source "${ROOT}/tests/fixtures/readme-changelog" \
  --slug wpbr-readme \
  --version 1.2.3 \
  --zip "${ROOT}/build/wpbr-dummy-1.2.3.zip" \
  --out "${readme_meta}"
php tests/stub-call.php expect-metadata-file "${readme_meta}" '{"changelog":"* Readme changelog.","requires_wp":"6.5","requires_php":"7.4","tested_wp":"6.8"}'
echo "metadata came from the changelog section, readme Tested up to, and inputs"

echo "CASE version-mismatch"
php tests/stub-call.php reset
phase_file="$(mktemp)"
export SLUG="wpbr-mismatch"
export PACKAGE_DIRECTORY="tests/fixtures/version-mismatch"
export TEST_COMMAND="php tests/run.php"
export LINT_COMMAND="php tests/lint.php"
if run_release "${phase_file}"; then
  echo "version mismatch was allowed to continue" >&2
  exit 1
fi
assert_phases "${phase_file}" php-lint tests lint
php tests/stub-call.php expect-no-upload
echo "version mismatch failed before upload"

echo "CASE failing-test"
php tests/stub-call.php reset
phase_file="$(mktemp)"
export SLUG="wpbr-bad-test"
export PACKAGE_DIRECTORY="tests/fixtures/failing-test"
export TEST_COMMAND="php tests/run.php"
export LINT_COMMAND="php tests/lint.php"
if run_release "${phase_file}"; then
  echo "failing tests were allowed to continue" >&2
  exit 1
fi
assert_phases "${phase_file}" php-lint
php tests/stub-call.php expect-no-upload
echo "failing test failed before upload"

echo "CASE failing-lint"
php tests/stub-call.php reset
phase_file="$(mktemp)"
export SLUG="wpbr-bad-lint"
export PACKAGE_DIRECTORY="tests/fixtures/failing-lint"
export TEST_COMMAND="php tests/run.php"
export LINT_COMMAND="php tests/lint.php"
if run_release "${phase_file}"; then
  echo "failing lint was allowed to continue" >&2
  exit 1
fi
assert_phases "${phase_file}" php-lint tests
php tests/stub-call.php expect-no-upload
echo "failing lint failed before upload"

echo "CASE reused-version"
php tests/stub-call.php reset
phase_file="$(mktemp)"
export SLUG="wpbr-dummy"
export PACKAGE_DIRECTORY="tests/dummy-plugin"
export TEST_COMMAND="php tests/run.php"
export LINT_COMMAND="php tests/lint.php"
if ! run_release "${phase_file}"; then
  echo "first upload of the reused-version case failed" >&2
  exit 1
fi
first_sha="$(php tests/stub-call.php sha "${ROOT}/build/wpbr-dummy-1.2.3.zip")"
phase_file="$(mktemp)"
if run_release "${phase_file}"; then
  echo "reused version was accepted" >&2
  exit 1
fi
assert_phases "${phase_file}" php-lint tests lint version composer zip
php tests/stub-call.php expect-conflict
if [[ "$(php tests/stub-call.php stored-sha)" != "${first_sha}" ]]; then
  echo "stored sha256 changed after 409" >&2
  exit 1
fi
echo "reused version returned 409 and stayed unpublished"

echo "CASE missing-changelog"
php tests/stub-call.php reset
phase_file="$(mktemp)"
err="$(mktemp)"
export SLUG="wpbr-missing"
export PACKAGE_DIRECTORY="tests/fixtures/missing-changelog"
export TEST_COMMAND="php tests/run.php"
export LINT_COMMAND="php tests/lint.php"
set +e
WPBR_PHASE_FILE="${phase_file}" bash "${ROOT}/scripts/release.sh" >"${err}" 2>&1
code=$?
set -e
cat "${err}"
if [[ "${code}" -eq 0 ]]; then
  echo "missing changelog was uploaded" >&2
  exit 1
fi
assert_phases "${phase_file}" php-lint tests lint version
php tests/stub-call.php expect-no-upload
if ! grep -q 'missing changelog for 1.2.3 before upload' "${err}"; then
  echo "missing changelog did not fail with the metadata error" >&2
  exit 1
fi
echo "missing changelog failed before upload"

must_yank() {
  local out
  out="$(mktemp)"
  set +e
  WPBR_YANK_REASON="${1:-}" bash "${ROOT}/scripts/yank.sh" >"${out}" 2>&1
  local code=$?
  set -e
  cat "${out}"
  if [[ "${code}" -ne 0 ]]; then
    echo "yank failed" >&2
    exit 1
  fi
  if ! grep -q "yanked id=${RELEASE_ID} status=yanked" "${out}"; then
    echo "yank output missing the status line" >&2
    exit 1
  fi
}

must_fail_yank() {
  local needle="$1"
  local out
  out="$(mktemp)"
  set +e
  WPBR_YANK_REASON="" bash "${ROOT}/scripts/yank.sh" >"${out}" 2>&1
  local code=$?
  set -e
  cat "${out}"
  if [[ "${code}" -eq 0 ]]; then
    echo "yank was accepted" >&2
    exit 1
  fi
  if ! grep -q "${needle}" "${out}"; then
    echo "yank failure did not mention ${needle}" >&2
    exit 1
  fi
}

must_conflict() {
  local out
  out="$(mktemp)"
  set +e
  php tests/stub-call.php upload "$1" "$2" "$3" >"${out}" 2>&1
  local code=$?
  set -e
  cat "${out}"
  if [[ "${code}" -eq 0 ]]; then
    echo "reupload of $1 $2 was accepted" >&2
    exit 1
  fi
  if ! grep -q 'upload HTTP 409 code=invalid_request' "${out}"; then
    echo "reupload of $1 $2 was not 409 invalid_request" >&2
    exit 1
  fi
}

work="$(mktemp -d)"

echo "CASE yank-rollback"
php tests/stub-call.php reset
php tests/stub-call.php pack-plugin wpbr-rollback 1.0.0 "${work}/1.0.0.zip" >/dev/null
php tests/stub-call.php pack-plugin wpbr-rollback 1.1.0 "${work}/1.1.0.zip" newer >/dev/null
old_id="$(php tests/stub-call.php upload wpbr-rollback 1.0.0 "${work}/1.0.0.zip")"
new_id="$(php tests/stub-call.php upload wpbr-rollback 1.1.0 "${work}/1.1.0.zip")"
php tests/stub-call.php publish "${old_id}"
php tests/stub-call.php publish "${new_id}"
php tests/stub-call.php expect-update wpbr-rollback 1.0.0 '{"update_available":true,"version":"1.1.0","allow_downgrade":false}'
export RELEASE_ID="${new_id}"
must_yank "bad build"
if [[ "$(php tests/stub-call.php status-of wpbr-rollback 1.1.0)" != "yanked" ]]; then
  echo "yank did not set status yanked" >&2
  exit 1
fi
must_yank ""
php tests/stub-call.php yank-auth empty "${RELEASE_ID}" 200 yanked
php tests/stub-call.php yank-auth reason "${RELEASE_ID}" 200 yanked
php tests/stub-call.php expect-update wpbr-rollback 1.1.0 '{"update_available":true,"version":"1.0.0","allow_downgrade":true}'
php tests/stub-call.php expect-update wpbr-rollback 9.9.9 '{"update_available":true,"version":"1.0.0","allow_downgrade":true}'
php tests/stub-call.php expect-update wpbr-rollback 1.0.0 '{"update_available":false}'
php tests/stub-call.php expect-info wpbr-rollback 1.0.0
php tests/stub-call.php expect-download-blocked "${new_id}"
must_conflict wpbr-rollback 1.1.0 "${work}/1.1.0.zip"
php tests/stub-call.php pack-plugin wpbr-rollback 1.1.0 "${work}/1.1.0-other.zip" other >/dev/null
must_conflict wpbr-rollback 1.1.0 "${work}/1.1.0-other.zip"
php tests/stub-call.php expect-tombstone wpbr-rollback 1.1.0
php tests/stub-call.php expect-count 2
if [[ "$(php tests/stub-call.php status-of wpbr-rollback 1.0.0)" != "published" ]]; then
  echo "previous release was not left published" >&2
  exit 1
fi
echo "yank offered the previous release with allow_downgrade and refused the same version"

echo "CASE yank-middle"
php tests/stub-call.php reset
php tests/stub-call.php pack-plugin wpbr-rollback 1.0.0 "${work}/m0.zip" >/dev/null
php tests/stub-call.php pack-plugin wpbr-rollback 1.1.0 "${work}/m1.zip" >/dev/null
php tests/stub-call.php pack-plugin wpbr-rollback 1.2.0 "${work}/m2.zip" >/dev/null
m0="$(php tests/stub-call.php upload wpbr-rollback 1.0.0 "${work}/m0.zip")"
m1="$(php tests/stub-call.php upload wpbr-rollback 1.1.0 "${work}/m1.zip")"
m2="$(php tests/stub-call.php upload wpbr-rollback 1.2.0 "${work}/m2.zip")"
php tests/stub-call.php publish "${m0}"
php tests/stub-call.php publish "${m1}"
php tests/stub-call.php publish "${m2}"
export RELEASE_ID="${m1}"
must_yank "middle"
php tests/stub-call.php expect-update wpbr-rollback 1.1.0 '{"update_available":true,"version":"1.2.0","allow_downgrade":false}'
echo "yanking a middle release still offers a newer published release"

echo "CASE yank-draft"
php tests/stub-call.php reset
php tests/stub-call.php pack-plugin wpbr-rollback 1.4.0 "${work}/draft.zip" >/dev/null
draft_id="$(php tests/stub-call.php upload wpbr-rollback 1.4.0 "${work}/draft.zip")"
export RELEASE_ID="${draft_id}"
must_fail_yank "code=invalid_request"
if [[ "$(php tests/stub-call.php status-of wpbr-rollback 1.4.0)" != "draft" ]]; then
  echo "draft yank changed the status" >&2
  exit 1
fi
php tests/stub-call.php expect-update wpbr-rollback 1.0.0 '{"update_available":false}'
php tests/stub-call.php expect-info wpbr-rollback null
echo "yanking a draft returned 409 and left it unpublished"

echo "CASE yank-only"
php tests/stub-call.php reset
php tests/stub-call.php pack-plugin wpbr-rollback 2.0.0 "${work}/only.zip" >/dev/null
only_id="$(php tests/stub-call.php upload wpbr-rollback 2.0.0 "${work}/only.zip")"
php tests/stub-call.php publish "${only_id}"
export RELEASE_ID="${only_id}"
must_yank "only"
php tests/stub-call.php expect-update wpbr-rollback 2.0.0 '{"update_available":false}'
php tests/stub-call.php expect-update wpbr-rollback 0.9.0 '{"update_available":false}'
must_conflict wpbr-rollback 2.0.0 "${work}/only.zip"
echo "yanking the only published release offers nothing"

echo "CASE yank-auth"
php tests/stub-call.php reset
php tests/stub-call.php yank-auth none 1 401 unauthorized
php tests/stub-call.php yank-auth bearer 1 401 unauthorized
php tests/stub-call.php yank-auth customer-password 1 401 unauthorized
php tests/stub-call.php yank-auth nocap 1 403 forbidden
php tests/stub-call.php yank-auth bad-body 1 400 invalid_request
php tests/stub-call.php reject-forbidden
export RELEASE_ID="99999"
must_fail_yank "release_not_found"
echo "yank auth, draft body, and unknown id failed closed"

echo "CASE pin-and-license"
php tests/stub-call.php reset
php tests/stub-call.php pack-plugin wpbr-rollback 1.0.0 "${work}/p0.zip" >/dev/null
php tests/stub-call.php pack-plugin wpbr-rollback 1.1.0 "${work}/p1.zip" >/dev/null
p0="$(php tests/stub-call.php upload wpbr-rollback 1.0.0 "${work}/p0.zip")"
p1="$(php tests/stub-call.php upload wpbr-rollback 1.1.0 "${work}/p1.zip")"
php tests/stub-call.php publish "${p0}"
php tests/stub-call.php publish "${p1}"
php tests/stub-call.php set-license active 1.0.0
php tests/stub-call.php expect-update wpbr-rollback 0.9.0 '{"update_available":true,"version":"1.0.0","allow_downgrade":false}'
php tests/stub-call.php expect-update wpbr-rollback 1.1.0 '{"update_available":true,"version":"1.0.0","allow_downgrade":true}'
php tests/stub-call.php expect-update wpbr-rollback 1.0.0 '{"update_available":false}'
php tests/stub-call.php set-license active "bad pin"
php tests/stub-call.php expect-update wpbr-rollback 0.9.0 '{"update_available":false}'
php tests/stub-call.php set-license revoked
php tests/stub-call.php expect-update wpbr-rollback 0.9.0 '{"update_available":false,"license":{"status":"revoked"}}'
php tests/stub-call.php set-license expired
php tests/stub-call.php expect-expired wpbr-rollback 0.9.0
php tests/stub-call.php set-license active
php tests/stub-call.php expect-update wpbr-rollback 0.9.0 '{"update_available":true,"version":"1.1.0","allow_downgrade":false}'
echo "pin, revoked, and expired follow the update-info rules"

echo "CASE delete-tombstone"
php tests/stub-call.php reset
php tests/stub-call.php pack-plugin wpbr-rollback 3.1.0 "${work}/gone.zip" >/dev/null
php tests/stub-call.php upload wpbr-rollback 3.1.0 "${work}/gone.zip" >/dev/null
php tests/stub-call.php drop-release
php tests/stub-call.php expect-count 0
php tests/stub-call.php expect-tombstone wpbr-rollback 3.1.0
must_conflict wpbr-rollback 3.1.0 "${work}/gone.zip"
php tests/stub-call.php expect-count 0
echo "removing the row left the version tombstoned"

echo "SELF-TEST OK sha256=${first_sha}"
