<?php
require '/var/www/phpgrc/current/api/vendor/autoload.php';
$app = require '/var/www/phpgrc/current/api/bootstrap/app.php';
$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap();
$driver = $app->make('App\\Auth\\Idp\\Drivers\\SamlIdpDriver');
$reflection = new ReflectionClass($driver);
$method = $reflection->getMethod('signRedirectPayload');
$method->setAccessible(true);
$payload = 'SAMLRequest=dummy&SigAlg=' . rawurlencode('http://www.w3.org/2001/04/xmldsig-more#rsa-sha256');
$signature = $method->invoke($driver, $payload);
$certificate = config('saml.sp.x509cert');
$public = openssl_get_publickey("-----BEGIN CERTIFICATE-----\n" . trim($certificate) . "\n-----END CERTIFICATE-----\n");
var_dump(openssl_verify($payload, base64_decode($signature), $public, OPENSSL_ALGO_SHA256));
while ($err = openssl_error_string()) {
    echo "OPENSSL: $err\n";
}
