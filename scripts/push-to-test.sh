#!/usr/bin/env bash
# phpGRC: Push working tree to installer test box via scp and flip 'current'.
# Deterministic, idempotent, and safe (atomic symlink swap).
# Requirements (local): bash, tar, ssh, scp, sha256sum
# Requirements (remote): bash, tar, sha256sum, ln, mkdir, systemctl (or service)
# Usage:
#   ./scripts/push-to-test.sh [user@]host [/opt/phpgrc] [apache-service-name]
# Defaults:
#   host: install-test.phpgrc.gruntlabs.net
#   path: /opt/phpgrc
#   svc : apache2
#
# Examples:
#   ./scripts/push-to-test.sh
#   ./scripts/push-to-test.sh ubuntu@install-test.phpgrc.gruntlabs.net
#   ./scripts/push-to-test.sh root@10.0.0.50 /opt/phpgrc httpd

set -euo pipefail
IFS=$'\n\t'

# ------------ Config (override via args/env) ------------
TEST_HOST="administrator@172.16.0.47"
TEST_PATH="${2:-${TEST_PATH:-/opt/phpgrc}}"
APACHE_SVC="${3:-${APACHE_SVC:-apache2}}"

# Paths packaged from repo root (keep aligned with Charter/monorepo)
PACKAGE_DIRS=(api web docs scripts .github)

# Exclusions (speed/safety). Do NOT exclude composer files.
EXCLUDES=(
  "--exclude=.git"
  "--exclude=node_modules"
  "--exclude=**/.DS_Store"
)

# Release naming
STAMP="$(date +%Y%m%d%H%M%S)"
RELEASE_NAME="phpgrc-${STAMP}"
ARCHIVE_LOCAL="${RELEASE_NAME}.tar.gz"
CHECKSUM_LOCAL="${ARCHIVE_LOCAL}.sha256"

# Remote paths
REMOTE_RELEASES="${TEST_PATH}/releases"
REMOTE_SHARED="${TEST_PATH}/shared"
REMOTE_CURRENT="${TEST_PATH}/current"
REMOTE_ARCHIVE="${REMOTE_RELEASES}/${ARCHIVE_LOCAL}"
REMOTE_DIR="${REMOTE_RELEASES}/${RELEASE_NAME}"
REMOTE_CHECKSUM="${REMOTE_RELEASES}/$(basename "${CHECKSUM_LOCAL}")"

# Sudo (in case remote ops require elevation)
SUDO="${SUDO:-sudo}"

# ------------ Preconditions ------------
need() { command -v "$1" >/dev/null 2>&1 || { echo "Missing dependency: $1" >&2; exit 1; }; }
need tar; need ssh; need scp; need sha256sum

if [[ ! -d ".github" ]] || [[ ! -d "scripts" ]]; then
  echo "Run from repo root (must contain .github/ and scripts/)" >&2
  exit 1
fi

echo "Target host : ${TEST_HOST}"
echo "Install path: ${TEST_PATH}"
echo "Apache svc  : ${APACHE_SVC}"
echo "Release     : ${RELEASE_NAME}"
echo

# ------------ Package ------------
echo "→ Creating release archive: ${ARCHIVE_LOCAL}"
tar -czf "${ARCHIVE_LOCAL}" "${EXCLUDES[@]}" "${PACKAGE_DIRS[@]}"

echo "→ Computing checksum"
sha256sum "${ARCHIVE_LOCAL}" > "${CHECKSUM_LOCAL}"

# ------------ Remote prep ------------
echo "→ Ensuring remote directories exist"
ssh -t "${TEST_HOST}" "${SUDO} mkdir -p '${REMOTE_RELEASES}' '${REMOTE_SHARED}' '${REMOTE_CURRENT}' && ${SUDO} chown -R \$(id -un):\$(id -gn) '${TEST_PATH}' || true"

# ------------ Transfer ------------
echo "→ Uploading archive and checksum"
scp "${ARCHIVE_LOCAL}" "${CHECKSUM_LOCAL}" "${TEST_HOST}:${REMOTE_RELEASES}/"

# ------------ Verify + Unpack on remote ------------
read -r -d '' REMOTE_CMDS <<EOF || true
set -euo pipefail
echo "→ Verifying checksum on remote"
cd "${REMOTE_RELEASES}"
sha256sum -c "$(basename "${CHECKSUM_LOCAL}")"

echo "→ Unpacking to ${REMOTE_DIR}"
mkdir -p "${REMOTE_DIR}"
tar -xzf "$(basename "${ARCHIVE_LOCAL}")" -C "${REMOTE_DIR}"

# Chown to www-data only if sudo available and user can escalate
if id www-data >/dev/null 2>&1; then
  if command -v sudo >/dev/null 2>&1; then
    ${SUDO} chown -R www-data:www-data "${REMOTE_DIR}" || echo "(!) Skipping chown to www-data"
  else
    echo "(!) sudo not present; leaving ownership as-is"
  fi
fi

echo "→ Atomically updating 'current' symlink"
${SUDO} ln -sfn "${REMOTE_DIR}" "${REMOTE_CURRENT}"

echo "→ Reloading web service: ${APACHE_SVC}"
if command -v systemctl >/dev/null 2>&1; then
  ${SUDO} systemctl reload "${APACHE_SVC}" || ${SUDO} systemctl restart "${APACHE_SVC}" || echo "(!) Reload failed (likely sudo). Reload manually."
else
  ${SUDO} service "${APACHE_SVC}" reload || ${SUDO} service "${APACHE_SVC}" restart || echo "(!) Reload failed (likely sudo). Reload manually."
fi

echo "✓ Deploy complete: ${REMOTE_CURRENT} → ${REMOTE_DIR}"
EOF

echo "→ Executing remote deployment steps"
ssh "${TEST_HOST}" "${REMOTE_CMDS}"

# ------------ Done ------------
echo
echo "All set. Current now points to: ${REMOTE_DIR}"
echo "Tip: Keep installer logs under ${REMOTE_SHARED}/logs/installer/ (persist across releases)."

# ------------ Cleanup (optional: keep artifacts locally for audit) ------------
# Uncomment to remove local artifacts after push:
# rm -f "${ARCHIVE_LOCAL}" "${CHECKSUM_LOCAL}"
