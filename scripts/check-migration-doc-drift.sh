#!/usr/bin/env bash
set -euo pipefail

BASE_REF="${BASE_REF:-${GITHUB_BASE_REF:-}}"
if [[ -z "${BASE_REF}" ]]; then
  # fallback: previous commit
  DIFF_RANGE="HEAD~1"
else
  # ensure base is fetched in CI
  git fetch --no-tags --depth=1 origin "${BASE_REF}" || true
  DIFF_RANGE="origin/${BASE_REF}...HEAD"
fi

changed_migrations=$(git diff --name-only --diff-filter=ACMRTUXB "${DIFF_RANGE}" -- api/database/migrations || true)
changed_schema=$(git diff --name-only --diff-filter=ACMRTUXB "${DIFF_RANGE}" -- docs/db/DB-SCHEMA.md || true)

if [[ -n "${changed_migrations}" ]]; then
  if [[ -z "${changed_schema}" ]]; then
    echo "ERROR: Migrations changed but docs/db/DB-SCHEMA.md did not."
    echo "Changed migration files:"
    echo "${changed_migrations}"
    exit 2
  fi
fi

echo "Migration/doc drift check OK."
