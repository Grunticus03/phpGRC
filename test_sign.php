<?php
require '/var/www/phpgrc/current/api/vendor/autoload.php';
$app = require '/var/www/phpgrc/current/api/bootstrap/app.php';
$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap();
$driver = $app->make('App\\Auth\\Idp\\Drivers\\SamlIdpDriver');
$reflection = new ReflectionClass($driver);
$method = $reflection->getMethod('signRedirectPayload');
$method->setAccessible(true);
try {
    $signature = $method->invoke($driver, 'SAMLRequest=dummy&SigAlg=http://www.w3.org/2001/04/xmldsig-more#rsa-sha256');
    var_dump(strlen($signature) > 0);
} catch (Throwable $e) {
    echo 'EXCEPTION: '.$e->getMessage()."\n";
}
