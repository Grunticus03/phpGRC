<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller as BaseController;

final class OpenApiController extends BaseController
{
    /**
     * Serve the OpenAPI YAML spec from /docs/api/openapi.yaml.
     */
    public function yaml(): Response
    {
        $path = base_path('docs/api/openapi.yaml');

        if (! is_file($path)) {
            return response('Spec not found', 404, ['Content-Type' => 'text/plain']);
        }

        $content = (string) file_get_contents($path);

        // Return as application/yaml without charset.
        return response($content, 200, ['Content-Type' => 'application/yaml']);
    }
}
