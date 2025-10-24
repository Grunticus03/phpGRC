<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\Idp\Drivers\EntraIdpDriver;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidatorFactory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class EntraIdpDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container;
        $loader = new ArrayLoader;
        $translator = new Translator($loader, 'en');
        $validatorFactory = new ValidatorFactory($translator, $container);

        $container->instance('translator', $translator);
        $container->instance('validator', $validatorFactory);

        Container::setInstance($container);
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    private function makeDriver(): EntraIdpDriver
    {
        $handler = HandlerStack::create(new MockHandler([]));
        $client = new Client([
            'handler' => $handler,
            'http_errors' => false,
        ]);

        return new EntraIdpDriver($client, new NullLogger);
    }

    public function test_normalize_requires_https_issuer(): void
    {
        $driver = $this->makeDriver();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Issuer must be a valid HTTPS URL.');

        $driver->normalizeConfig([
            'tenant_id' => 'abc12345-1111-2222-3333-abcdefabcdef',
            'issuer' => 'http://login.microsoftonline.com/tenant/v2.0',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
        ]);
    }
}
