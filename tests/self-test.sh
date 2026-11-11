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
php tests/stub-call.php reject-unauthorized
php tests/stub-call.php reject-bad-sha wpbr-dummy "${ROOT}/build/wpbr-dummy-1.2.3.zip"
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

echo "SELF-TEST OK sha256=${first_sha}"
