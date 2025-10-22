<?php
require '/var/www/phpgrc/current/api/vendor/autoload.php';
$app = require '/var/www/phpgrc/current/api/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
$provider = App\Models\IdpProvider::where('name', 'Keycloak SAML')->first();
if (! $provider) {
    echo "Provider not found\n";
    exit(1);
}
$config = (array) $provider->config;
/** @var App\Auth\Idp\Drivers\SamlIdpDriver $driver */
$driver = $app->make(App\Auth\Idp\Drivers\SamlIdpDriver::class);
$result = $driver->checkHealth($config);
$details = $result->details;
$url = $details['request']['url'] ?? null;
if (! $url) {
    var_export($details);
    exit(0);
}
parse_str(parse_url($url, PHP_URL_QUERY), $params);
var_export($params);
