<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller as BaseController;

final class OpenApiController extends BaseController
{
    public function yaml(): Response
    {
        $path = $this->findSpecPath();

        if ($path === null) {
            return response('Spec not found', 404, ['Content-Type' => 'text/plain']);
        }

        $content = (string) file_get_contents($path);

        return response($content, 200, ['Content-Type' => 'application/yaml']);
    }

    private function findSpecPath(): ?string
    {
        $candidates = [
            base_path('docs/api/openapi.yaml'),     // api/docs/api/openapi.yaml
            base_path('../docs/api/openapi.yaml'),  // monorepo-root/docs/api/openapi.yaml
        ];

        foreach ($candidates as $p) {
            $real = realpath($p);
            if ($real !== false && is_file($real)) {
                return $real;
            }
        }

        return null;
    }
}
