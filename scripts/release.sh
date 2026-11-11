#!/usr/bin/env bash
# Build {slug}-{version}.zip and upload it as a draft release.
# Tests and lint run before composer, the zip, the GitHub Release, and the upload.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

: "${SLUG:?SLUG is required}"
: "${TEST_COMMAND:?TEST_COMMAND is required}"
: "${LINT_COMMAND:?LINT_COMMAND is required}"

PACKAGE_DIRECTORY="${PACKAGE_DIRECTORY:-.}"
SKIP_GITHUB_RELEASE="${SKIP_GITHUB_RELEASE:-false}"
USE_STUB="${USE_STUB:-false}"
INPUT_VERSION="${INPUT_VERSION:-}"
STUB_PORT="${STUB_PORT:-8765}"
GITHUB_REF_TYPE="${GITHUB_REF_TYPE:-}"
GITHUB_REF_NAME="${GITHUB_REF_NAME:-}"

mask() {
  local value="$1"
  if [[ -n "${value}" ]]; then
    printf '::add-mask::%s\n' "${value}"
  fi
}

phase() {
  printf 'phase %s\n' "$1"
  if [[ -n "${WPBR_PHASE_FILE:-}" ]]; then
    printf '%s\n' "$1" >> "${WPBR_PHASE_FILE}"
  fi
}

if [[ "${USE_STUB}" == "true" ]]; then
  export WPBR_RELEASE_USER="wpbr-release"
  export WPBR_RELEASE_APP_PASSWORD="stub-only-not-a-secret"
  export WPBR_SERVER_URL="http://127.0.0.1:${STUB_PORT}"
  export WPBR_STUB_PUBLIC_URL="${WPBR_SERVER_URL}"
  export WPBR_STUB_STATE="${WPBR_STUB_STATE:-${GITHUB_WORKSPACE:-${ROOT}}/stub-state.json}"
  export WPBR_STUB_STORAGE="${WPBR_STUB_STORAGE:-${GITHUB_WORKSPACE:-${ROOT}}/stub-storage}"
  mkdir -p "${WPBR_STUB_STORAGE}"
  if [[ ! -f "${WPBR_STUB_STATE}" ]]; then
    printf '%s\n' '{"next_id":1,"releases":[],"requests":[],"versions":[]}' > "${WPBR_STUB_STATE}"
  fi
  php -S "127.0.0.1:${STUB_PORT}" -t "${ROOT}/tests/stub-server" "${ROOT}/tests/stub-server/server.php" \
    > "${GITHUB_WORKSPACE:-${ROOT}}/stub-server.log" 2>&1 &
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
    exit 1
  fi
fi

mask "${WPBR_RELEASE_USER:-}"
mask "${WPBR_RELEASE_APP_PASSWORD:-}"
mask "${WPBR_SERVER_URL:-}"

if [[ ! "${SLUG}" =~ ^[a-z0-9]+(-[a-z0-9]+)*$ ]]; then
  echo "slug is invalid" >&2
  exit 1
fi

raw_version=""
if [[ -n "${INPUT_VERSION}" ]]; then
  if [[ "${GITHUB_REF_TYPE}" == "tag" && "${INPUT_VERSION}" != "${GITHUB_REF_NAME}" ]]; then
    echo "version input does not match the tag" >&2
    exit 1
  fi
  raw_version="${INPUT_VERSION}"
elif [[ "${GITHUB_REF_TYPE}" == "tag" ]]; then
  raw_version="${GITHUB_REF_NAME}"
else
  echo "push a vX.Y.Z tag or pass version" >&2
  exit 1
fi

if [[ ! "${raw_version}" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "version must look like vX.Y.Z" >&2
  exit 1
fi
version="${raw_version#v}"
tag="v${version}"

if [[ "${PACKAGE_DIRECTORY}" == /* ]]; then
  pkg="${PACKAGE_DIRECTORY}"
else
  pkg="${ROOT}/${PACKAGE_DIRECTORY}"
fi
if [[ ! -d "${pkg}" ]]; then
  echo "package directory does not exist" >&2
  exit 1
fi
cd "${pkg}"

found=0
while IFS= read -r -d '' file; do
  found=1
  php -l "${file}"
done < <(find . -name '*.php' -not -path './vendor/*' -not -path './vendor-prefixed/*' -not -path './node_modules/*' -print0)
if [[ "${found}" -ne 1 ]]; then
  echo "no PHP files to lint" >&2
  exit 1
fi
phase php-lint

bash -c "${TEST_COMMAND}"
phase tests

bash -c "${LINT_COMMAND}"
phase lint

php "${ROOT}/scripts/package.php" check --source "${pkg}" --slug "${SLUG}" --version "${version}"
phase version

if [[ -z "${WPBR_SERVER_URL:-}" || -z "${WPBR_RELEASE_USER:-}" || -z "${WPBR_RELEASE_APP_PASSWORD:-}" ]]; then
  echo "WPBR_SERVER_URL, WPBR_RELEASE_USER, and WPBR_RELEASE_APP_PASSWORD are required" >&2
  exit 1
fi

if [[ -f composer.json ]]; then
  if ! command -v composer >/dev/null 2>&1; then
    echo "composer is required" >&2
    exit 1
  fi
  if php "${ROOT}/scripts/package.php" has-strauss; then
    composer install --no-interaction --prefer-dist --no-progress
    target="$(php "${ROOT}/scripts/package.php" strauss-target)"
    if [[ ! -x vendor/bin/strauss && ! -f vendor/bin/strauss ]]; then
      echo "extra.strauss is set but vendor/bin/strauss is missing" >&2
      exit 1
    fi
    php -d memory_limit=1G vendor/bin/strauss
    if [[ ! -d "${target}" ]]; then
      echo "Strauss did not create ${target}" >&2
      exit 1
    fi
    composer install --no-dev --no-interaction --prefer-dist --no-progress
  else
    composer install --no-dev --no-interaction --prefer-dist --no-progress
  fi
  phase composer
fi

build_dir="${GITHUB_WORKSPACE:-${ROOT}}/build"
mkdir -p "${build_dir}"
zip_path="${build_dir}/${SLUG}-${version}.zip"
meta_path="${build_dir}/${SLUG}-${version}.metadata.json"
result_path="${build_dir}/result.json"
export WPBR_RESULT_FILE="${result_path}"

php "${ROOT}/scripts/package.php" zip --source "${pkg}" --slug "${SLUG}" --out "${zip_path}"
php "${ROOT}/scripts/package.php" metadata --source "${pkg}" --slug "${SLUG}" --version "${version}" --zip "${zip_path}" --out "${meta_path}"
phase zip

if [[ "${SKIP_GITHUB_RELEASE}" == "true" ]]; then
  echo "skip-github-release: not creating a GitHub Release"
else
  if [[ -f "${pkg}/CHANGELOG.md" ]]; then
    notes="${pkg}/CHANGELOG.md"
  else
    notes="$(mktemp)"
    printf 'Release %s\n' "${tag}" > "${notes}"
  fi
  gh release create "${tag}" "${zip_path}" --title "${tag}" --notes-file "${notes}"
  phase github-release
fi

php "${ROOT}/scripts/upload-draft.php" "${zip_path}" "${meta_path}"
phase upload
