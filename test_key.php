<?php
require '/var/www/phpgrc/current/api/vendor/autoload.php';
$app = require '/var/www/phpgrc/current/api/bootstrap/app.php';
$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap();
$cfg = config('core.auth.saml.sp');
$key = openssl_pkey_get_private($cfg['private_key'] ?? '', $cfg['private_key_passphrase'] ?? '');
var_dump($key !== false);
if ($key === false) {
    while ($msg = openssl_error_string()) {
        echo "OPENSSL: $msg\n";
    }
}
