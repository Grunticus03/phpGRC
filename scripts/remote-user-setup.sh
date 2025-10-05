#!/usr/bin/env bash
set -euo pipefail

# Defaults (override with env vars)
DEPLOY_USER="${DEPLOY_USER:-deploy}"
DEPLOY_PATH="${DEPLOY_PATH:-/var/www/phpgrc}"
SSH_PORT="${SSH_PORT:-2332}"

# Create deploy user with login shell for rsync/ssh
if ! id -u "$DEPLOY_USER" >/dev/null 2>&1; then
  useradd -m -d "/home/${DEPLOY_USER}" -s /bin/bash "${DEPLOY_USER}"
fi

# SSH directory
install -d -m 700 "/home/${DEPLOY_USER}/.ssh"
touch "/home/${DEPLOY_USER}/.ssh/authorized_keys"
chmod 600 "/home/${DEPLOY_USER}/.ssh/authorized_keys"
chown -R "${DEPLOY_USER}:${DEPLOY_USER}" "/home/${DEPLOY_USER}/.ssh"

# Deploy directories
install -d -m 755 "${DEPLOY_PATH}/releases" "${DEPLOY_PATH}/shared"
chown -R "${DEPLOY_USER}:${DEPLOY_USER}" "${DEPLOY_PATH}"

echo "User ${DEPLOY_USER} ready. Add your GitHub Action public key to authorized_keys."
echo "Deploy path: ${DEPLOY_PATH}"
echo "SSH port: ${SSH_PORT}"
