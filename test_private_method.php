<?php
require '/var/www/phpgrc/current/api/vendor/autoload.php';
$app = require '/var/www/phpgrc/current/api/bootstrap/app.php';
$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap();
$resolver = $app->make(App\Services\Auth\SamlServiceProviderConfigResolver::class);
$key = $resolver->privateKey();
var_dump($key !== null);
if ($key !== null) {
    $resource = openssl_pkey_get_private($key);
    var_dump($resource !== false);
    if ($resource === false) {
        while ($msg = openssl_error_string()) {
            echo "OPENSSL: $msg\n";
        }
    }
}
