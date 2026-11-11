#!/usr/bin/env bash
# Yank one license-server release by numeric id.
# A draft is rejected. An already-yanked release returns yanked again.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

: "${RELEASE_ID:?RELEASE_ID is required}"

USE_STUB="${USE_STUB:-false}"
STUB_PORT="${STUB_PORT:-8765}"
WPBR_YANK_REASON="${WPBR_YANK_REASON:-}"

mask() {
  local value="$1"
  if [[ -n "${value}" ]]; then
    printf '::add-mask::%s\n' "${value}"
  fi
}

if [[ ! "${RELEASE_ID}" =~ ^[1-9][0-9]*$ ]]; then
  echo "release id must be the numeric id" >&2
  exit 1
fi

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
fi

mask "${WPBR_RELEASE_USER:-}"
mask "${WPBR_RELEASE_APP_PASSWORD:-}"
mask "${WPBR_SERVER_URL:-}"

if [[ -z "${WPBR_SERVER_URL:-}" || -z "${WPBR_RELEASE_USER:-}" || -z "${WPBR_RELEASE_APP_PASSWORD:-}" ]]; then
  echo "WPBR_SERVER_URL, WPBR_RELEASE_USER, and WPBR_RELEASE_APP_PASSWORD are required" >&2
  exit 1
fi

if [[ "${USE_STUB}" == "true" ]]; then
  stub_log="${GITHUB_WORKSPACE:-${ROOT}}/stub-server.log"
  mkdir -p "$(dirname "${stub_log}")"
  php -S "127.0.0.1:${STUB_PORT}" -t "${ROOT}/tests/stub-server" "${ROOT}/tests/stub-server/server.php" \
    > "${stub_log}" 2>&1 &
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

  # TEST ONLY. The self-test workflow has an empty stub, so seed one published
  # release and require RELEASE_ID to be that id. Real runs do not set use-stub.
  seed_zip="${GITHUB_WORKSPACE:-${ROOT}}/build/stub-seed.zip"
  mkdir -p "${GITHUB_WORKSPACE:-${ROOT}}/build"
  php "${ROOT}/tests/stub-call.php" pack-plugin wpbr-seed 0.0.1 "${seed_zip}" >/dev/null
  seed_id="$(php "${ROOT}/tests/stub-call.php" upload wpbr-seed 0.0.1 "${seed_zip}")"
  php "${ROOT}/tests/stub-call.php" publish "${seed_id}" >/dev/null
  if [[ "${seed_id}" != "${RELEASE_ID}" ]]; then
    echo "stub seeded id ${seed_id} but release id is ${RELEASE_ID}" >&2
    exit 1
  fi
fi

export WPBR_RESULT_FILE="${WPBR_RESULT_FILE:-${GITHUB_WORKSPACE:-${ROOT}}/build/yank-result.json}"
php "${ROOT}/scripts/yank.php" "${RELEASE_ID}"
