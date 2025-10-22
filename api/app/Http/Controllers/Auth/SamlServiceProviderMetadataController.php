<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\SamlServiceProviderConfigResolver;
use App\Services\Auth\SamlServiceProviderMetadataBuilder;
use Symfony\Component\HttpFoundation\Response;

final class SamlServiceProviderMetadataController extends Controller
{
    public function __construct(
        private readonly SamlServiceProviderConfigResolver $config,
        private readonly SamlServiceProviderMetadataBuilder $metadataBuilder
    ) {}

    public function __invoke(): Response
    {
        $sp = $this->config->resolve();
        $metadata = $this->metadataBuilder->generate($sp);

        return response($metadata, 200, [
            'Content-Type' => 'application/samlmetadata+xml; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="phpgrc-sp-metadata.xml"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
