<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller as BaseController;
use Symfony\Component\Yaml\Yaml;

final class OpenApiController extends BaseController
{
    public function yaml(): Response
    {
        $content = $this->loadSpecYaml();

        return response($content, 200, [
            'Content-Type' => 'application/yaml; charset=UTF-8',
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }

    public function json(): Response
    {
        $json = $this->tryLoadSpecJsonFile();
        if ($json !== null) {
            return response($json, 200, [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Cache-Control' => 'no-store, max-age=0',
            ]);
        }

        $yaml = $this->loadSpecYaml();

        try {
            /** @var array<string, mixed> $data */
            $data = Yaml::parse($yaml);
            $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                throw new \RuntimeException('json_encode failed');
            }

            return response($json, 200, [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Cache-Control' => 'no-store, max-age=0',
            ]);
        } catch (\Throwable $e) {
            $fallback = json_encode([
                'ok' => false,
                'code' => 'OPENAPI_CONVERT_FAILED',
                'error' => $e->getMessage(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $payload = ($fallback === false) ? '{"ok":false}' : $fallback;

            return response($payload, 500, [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Cache-Control' => 'no-store, max-age=0',
            ]);
        }
    }

    private function loadSpecYaml(): string
    {
        $candidates = [
            base_path('docs/api/openapi.yaml'),
            base_path('../docs/api/openapi.yaml'),
        ];

        foreach ($candidates as $p) {
            $real = realpath($p);
            if ($real !== false && is_file($real)) {
                $data = @file_get_contents($real);
                if ($data !== false && $data !== '') {
                    return $data;
                }
            }
        }

        return <<<YAML
openapi: 3.1.0
info:
  title: phpGRC API
  version: 0.4.0
servers:
  - url: /api
paths:
  /health:
    get:
      summary: Health check
      description: Simple liveness probe for environment checks.
      responses:
        '200':
          description: OK
YAML;
    }

    private function tryLoadSpecJsonFile(): ?string
    {
        $candidates = [
            base_path('docs/api/openapi.json'),
            base_path('../docs/api/openapi.json'),
        ];

        foreach ($candidates as $p) {
            $real = realpath($p);
            if ($real !== false && is_file($real)) {
                $data = @file_get_contents($real);
                if ($data !== false && $data !== '') {
                    return $data;
                }
            }
        }

        return null;
    }
}
