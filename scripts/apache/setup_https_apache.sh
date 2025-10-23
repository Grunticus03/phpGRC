#!/usr/bin/env bash
# Purpose: Clean, idempotent Apache HTTPS setup on Ubuntu 24.04 for phpGRC
# Mode: deterministic (set -euo pipefail), HTTPS only (443), no port 80 vhost
# Uses Ubuntu's snakeoil certs initially; you can swap to real certs later.

set -euo pipefail

SERVER_NAME="${SERVER_NAME:-phpgrc.gruntlabs.net}"
DEPLOY_PATH="${DEPLOY_PATH:-/var/www/phpgrc}"
DOCROOT="${DOCROOT:-${DEPLOY_PATH}/current/api/public}"
SITE_NAME="${SITE_NAME:-phpgrc-https.conf}"

# 1) Install Apache + SSL + headers if not present
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y apache2 ssl-cert
a2enmod ssl headers rewrite

# 2) Ensure deploy path exists (does NOT create app files; just directories)
install -d -m 755 "${DEPLOY_PATH}"
install -d -m 755 "${DEPLOY_PATH}/releases" "${DEPLOY_PATH}/shared"
install -d -m 755 "${DOCROOT}" || true

# 3) Create HTTPS-only vhost file
#    Uses snakeoil certs as placeholders:
#      SSLCertificateFile:      /etc/ssl/certs/ssl-cert-snakeoil.pem
#      SSLCertificateKeyFile:   /etc/ssl/private/ssl-cert-snakeoil.key
#    Swap these to your real certs later.
cat >/etc/apache2/sites-available/${SITE_NAME} <<'EOF'
<VirtualHost *:443>
    ServerName __SERVER_NAME__

    SSLEngine on
    SSLCertificateFile      /etc/ssl/certs/ssl-cert-snakeoil.pem
    SSLCertificateKeyFile   /etc/ssl/private/ssl-cert-snakeoil.key

    DocumentRoot __DOCROOT__
    <Directory __DOCROOT__>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        DirectoryIndex index.html index.php

        <Files "index.html">
            Header set Cache-Control "no-store, max-age=0"
        </Files>

        SetEnvIfNoCase Authorization "^(.*)" HTTP_AUTHORIZATION=$1
        RequestHeader set Authorization "%{HTTP_AUTHORIZATION}e" env=HTTP_AUTHORIZATION
    </Directory>

    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost"
    </FilesMatch>

    Alias /assets/ ${DOCROOT}/assets/
    <Directory ${DOCROOT}/assets>
        Options -Indexes
        AllowOverride None
        Require all granted
        Header set Cache-Control "public, max-age=31536000, immutable"
    </Directory>

    # Security headers (baseline)
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-XSS-Protection "0"
    Header always set Referrer-Policy "no-referrer-when-downgrade"
    Header always set Content-Security-Policy "default-src 'self'; connect-src 'self' https://login.microsoftonline.com; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data:; frame-ancestors 'self'; base-uri 'self'"

    # HSTS (HTTPS-only requirement)
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"

    ErrorLog  /var/log/apache2/phpgrc_error.log
    CustomLog /var/log/apache2/phpgrc_access.log combined

    RewriteEngine On

    RewriteCond %{REQUEST_URI} \.(?:css|js|map|woff2?|woff|svg|png|jpe?g|webp|ico)$ [NC]
    RewriteRule .* - [L,END]

    # Serve built SPA assets and other static files directly
    RewriteRule ^assets/ - [L]

    RewriteCond %{REQUEST_FILENAME} -f [OR]
    RewriteCond %{REQUEST_FILENAME} -d
    RewriteRule ^ - [L]

    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{HTTP_ACCEPT} (text/html|application/xhtml\+xml) [NC]
    RewriteCond %{DOCUMENT_ROOT}/index.html -f
    RewriteRule ^ index.html [L,END]

    # Laravel API and JSON endpoints
    RewriteCond %{REQUEST_METHOD} !^(GET|HEAD)$ [OR]
    RewriteCond %{HTTP_X_REQUESTED_WITH} XMLHttpRequest [NC,OR]
    RewriteCond %{HTTP_ACCEPT} !(text/html|application/xhtml\+xml) [NC]
    RewriteCond %{REQUEST_URI} ^/(admin|audit|auth|avatar|broadcasting|dashboard|docs|evidence|exports|favicon\.ico|health|integrations|me|metrics|oauth|openapi\.json|openapi\.yaml|rbac|reports|sanctum|settings|setup|telescope|up|users|_ignition|inertia) [NC]
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [QSA,L]

    # SPA history-mode fallback
    RewriteCond %{REQUEST_URI} !^/(admin|audit|auth|avatar|broadcasting|dashboard|docs|evidence|exports|favicon\.ico|health|integrations|me|metrics|oauth|openapi\.json|openapi\.yaml|rbac|reports|sanctum|settings|setup|telescope|up|users|_ignition|inertia) [NC]
    RewriteCond %{DOCUMENT_ROOT}/index.html -f
    RewriteRule ^$ index.html [L]

    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} !^/(admin|audit|auth|avatar|broadcasting|dashboard|docs|evidence|exports|favicon\.ico|health|integrations|me|metrics|oauth|openapi\.json|openapi\.yaml|rbac|reports|sanctum|settings|setup|telescope|up|users|_ignition|inertia) [NC]
    RewriteCond %{DOCUMENT_ROOT}/index.html -f
    RewriteRule ^ index.html [L]
</VirtualHost>
EOF

# 4) Substitute variables into vhost
sed -i "s#__SERVER_NAME__#${SERVER_NAME}#g" /etc/apache2/sites-available/${SITE_NAME}
sed -i "s#__DOCROOT__#${DOCROOT}#g"       /etc/apache2/sites-available/${SITE_NAME}

# 5) Enable site; ensure HTTPS-only (disable default :80 site if present)
a2ensite "${SITE_NAME}"
if [ -f /etc/apache2/sites-enabled/000-default.conf ]; then
  a2dissite 000-default.conf
fi

# 6) Open firewall for 443 (ignore if ufw not in use)
ufw allow 443/tcp >/dev/null 2>&1 || true

# 7) Reload Apache
systemctl reload apache2

echo "Apache HTTPS site enabled:"
echo "  ServerName  : ${SERVER_NAME}"
echo "  DocumentRoot: ${DOCROOT}"
echo "  Certs       : snakeoil (temporary) — replace with real certs when ready"
echo
echo "QA:"
echo "  - curl -kI https://${SERVER_NAME}/"
echo "  - ss -ltnp | grep ':443'"
