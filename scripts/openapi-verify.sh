#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SPEC_PATH="${ROOT_DIR}/docs/api/openapi.yaml"
BASELINE_PATH="${ROOT_DIR}/docs/api/baseline/openapi-0.4.7.yaml"
HTML_OUT="${ROOT_DIR}/build/openapi-diff.html"

if [[ ! -f "${SPEC_PATH}" ]]; then
  echo "Spec not found at ${SPEC_PATH}" >&2
  exit 1
fi

if [[ ! -f "${BASELINE_PATH}" ]]; then
  echo "Baseline spec not found at ${BASELINE_PATH}" >&2
  exit 1
fi

echo "🔍 Linting OpenAPI spec with Redocly..."
if [[ -f "${ROOT_DIR}/node_modules/.bin/redocly" ]]; then
  chmod +x "${ROOT_DIR}/node_modules/.bin/redocly" 2>/dev/null || true
  "${ROOT_DIR}/node_modules/.bin/redocly" lint "${SPEC_PATH}"
else
  npx --yes --package @redocly/cli@2.3.1 redocly lint "${SPEC_PATH}"
fi

run_openapi_diff() {
  local diff_cmd=("$@")
  echo "🧮 Running openapi-diff: ${diff_cmd[*]}"
  "${diff_cmd[@]}"
}

mkdir -p "${ROOT_DIR}/build"

if command -v openapi-diff >/dev/null 2>&1; then
  run_openapi_diff openapi-diff "${BASELINE_PATH}" "${SPEC_PATH}" \
    --fail-on-incompatible \
    --html "${HTML_OUT}"
elif command -v docker >/dev/null 2>&1; then
  run_openapi_diff docker run --rm \
    -v "${ROOT_DIR}":/workspace \
    -w /workspace \
    openapitools/openapi-diff:2.1.0 \
    /workspace/docs/api/baseline/openapi-0.4.7.yaml /workspace/docs/api/openapi.yaml \
    --fail-on-incompatible \
    --html /workspace/build/openapi-diff.html
elif command -v java >/dev/null 2>&1; then
  JAR_PATH="${ROOT_DIR}/build/openapi-diff.jar"
  JAR_VERSION="2.3.6"
  JAR_URL="https://repo1.maven.org/maven2/com/github/elibracha/openapi-diff/${JAR_VERSION}/openapi-diff-${JAR_VERSION}.jar"

  if [[ ! -f "${JAR_PATH}" ]]; then
    echo "⬇️  Downloading openapi-diff ${JAR_VERSION} jar..."
    curl -sSL "${JAR_URL}" -o "${JAR_PATH}"
  fi

  run_openapi_diff java -jar "${JAR_PATH}" \
    "${BASELINE_PATH}" "${SPEC_PATH}" \
    --fail-on-incompatible \
    --html "${HTML_OUT}"
elif command -v npx >/dev/null 2>&1; then
  run_openapi_diff npx --yes openapi-diff "${BASELINE_PATH}" "${SPEC_PATH}"
else
  echo "openapi-diff toolchain missing. Install openapi-diff, docker, or Java." >&2
  exit 127
fi

echo "✅ OpenAPI checks complete."
echo "Report (if generated): ${HTML_OUT}"
