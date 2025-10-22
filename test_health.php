<?php
require '/var/www/phpgrc/current/api/vendor/autoload.php';
$app = require '/var/www/phpgrc/current/api/bootstrap/app.php';
$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap();
use App\Models\IdpProvider;
use App\Auth\Idp\Drivers\SamlIdpDriver;
$provider = IdpProvider::where('name', 'Keycloak SAML')->first();
if (! $provider) {
    echo "Provider not found\n";
    exit(1);
}
$config = (array) $provider->config;
/** @var SamlIdpDriver $driver */
$driver = $app->make(SamlIdpDriver::class);
$result = $driver->checkHealth($config);
var_dump($result->status, $result->message);
if ($result->status !== 'ok') {
    var_export($result->details);
}
