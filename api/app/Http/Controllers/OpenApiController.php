<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller as BaseController;
use Symfony\Component\Yaml\Yaml;

final class OpenApiController extends BaseController
{
    public function yaml(Request $request): Response
    {
        $spec = $this->loadSpecYamlWithMeta();
        $content = $this->tryMutateYaml($spec['content']);

        return $this->respondWithCaching(
            $request,
            $content,
            'application/yaml',
            $spec['mtime']
        );
    }

    public function json(Request $request): Response
    {
        $jsonFile = $this->tryLoadSpecJsonFileWithMeta();
        if ($jsonFile !== null) {
            $content = $this->tryMutateJson($jsonFile['content']);
            return $this->respondWithCaching(
                $request,
                $content,
                'application/json',
                $jsonFile['mtime']
            );
        }

        $yaml = $this->loadSpecYamlWithMeta();
        try {
            /** @var array<string,mixed> $data */
            $data = Yaml::parse($yaml['content']) ?: [];
            $data = $this->injectCapabilityDisabledIntoDoc($data);
            $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                throw new \RuntimeException('json_encode failed');
            }

            return $this->respondWithCaching(
                $request,
                $json,
                'application/json',
                $yaml['mtime']
            );
        } catch (\Throwable $e) {
            $fallback = json_encode([
                'ok'   => false,
                'code' => 'OPENAPI_CONVERT_FAILED',
                'error'=> $e->getMessage(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $payload = ($fallback === false) ? '{"ok":false}' : $fallback;
            return response($payload, 500, ['Content-Type' => 'application/json']);
        }
    }

    /**
     * @return array{content: string, path: string|null, mtime: int|null}
     */
    private function loadSpecYamlWithMeta(): array
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
                    $mtime = @filemtime($real);
                    return [
                        'content' => $data,
                        'path'    => $real,
                        'mtime'   => ($mtime === false) ? null : $mtime,
                    ];
                }
            }
        }

        $content = <<<YAML
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
      responses:
        '200':
          description: OK
YAML;

        return [
            'content' => $content,
            'path'    => null,
            'mtime'   => null,
        ];
    }

    /**
     * @return array{content: string, path: string, mtime: int}|null
     */
    private function tryLoadSpecJsonFileWithMeta(): ?array
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
                    $mtime = @filemtime($real);
                    return [
                        'content' => $data,
                        'path'    => $real,
                        'mtime'   => ($mtime === false) ? time() : $mtime,
                    ];
                }
            }
        }
        return null;
    }

    /**
     * Strong ETag, exact Cache-Control, nosniff, Vary, optional Last-Modified, 304 on match.
     */
    private function respondWithCaching(Request $request, string $body, string $contentType, ?int $mtime): Response
    {
        $etag = '"sha256:' . hash('sha256', $body) . '"';
        $ifNoneMatch = (string) $request->headers->get('If-None-Match', '');

        $clientEtags = array_filter(array_map(static function (string $v): string {
            return trim($v);
        }, explode(',', $ifNoneMatch)));

        $clientEtagsUnquoted = array_map(static function (string $v): string {
            return trim($v, " \t\"");
        }, $clientEtags);

        $ourEtagUnquoted = trim($etag, "\"");
        $matches = in_array($etag, $clientEtags, true) || in_array($ourEtagUnquoted, $clientEtagsUnquoted, true);

        $status = $matches ? 304 : 200;

        $resp = response($matches ? '' : $body, $status);

        $h = $resp->headers;
        $h->set('Content-Type', $contentType);
        $h->set('ETag', $etag);
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('Vary', 'Accept-Encoding');
        if ($mtime !== null) {
            $h->set('Last-Modified', gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        }

        if (method_exists($h, 'removeCacheControlDirective')) {
            foreach (['private','public','no-cache','must-revalidate','proxy-revalidate','s-maxage','max-age','no-store','immutable'] as $dir) {
                $h->removeCacheControlDirective($dir);
            }
        }
        if (method_exists($h, 'addCacheControlDirective')) {
            $h->addCacheControlDirective('no-store', true);
            $h->addCacheControlDirective('max-age', '0');
        }
        $h->set('Cache-Control', 'no-store, max-age=0');

        return $resp;
    }

    /**
     * Attempt to mutate YAML content by parsing and injecting capability responses.
     */
    private function tryMutateYaml(string $yaml): string
    {
        try {
            /** @var array<string,mixed> $doc */
            $doc = Yaml::parse($yaml) ?: [];
            $doc = $this->injectCapabilityDisabledIntoDoc($doc);

            return Yaml::dump($doc, 10, 2);
        } catch (\Throwable) {
            return $yaml;
        }
    }

    /**
     * Attempt to mutate JSON content by decoding and injecting capability responses.
     */
    private function tryMutateJson(string $json): string
    {
        try {
            /** @var array<string,mixed>|null $doc */
            $doc = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($doc)) {
                return $json;
            }
            $doc = $this->injectCapabilityDisabledIntoDoc($doc);

            /** @var string $out */
            $out = json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            return $out;
        } catch (\Throwable) {
            return $json;
        }
    }

    /**
     * Injects:
     * - components.schemas.CapabilityError
     * - components.responses.CapabilityDisabled
     * - Attaches 403 to /audit/export.csv GET and /evidence POST if present
     *
     * @param array<string,mixed> $doc
     * @return array<string,mixed>
     */
    private function injectCapabilityDisabledIntoDoc(array $doc): array
    {
        /** @var array<string,mixed> $components */
        $components = isset($doc['components']) && is_array($doc['components']) ? $doc['components'] : [];

        /** @var array<string,mixed> $schemas */
        $schemas = isset($components['schemas']) && is_array($components['schemas']) ? $components['schemas'] : [];

        /** @var array<string,mixed> $respComp */
        $respComp = isset($components['responses']) && is_array($components['responses']) ? $components['responses'] : [];

        $schemas['CapabilityError'] = [
            'type'       => 'object',
            'required'   => ['ok', 'code'],
            'properties' => [
                'ok'         => ['type' => 'boolean', 'const' => false],
                'code'       => ['type' => 'string', 'enum' => ['CAPABILITY_DISABLED']],
                'capability' => ['type' => 'string'],
            ],
            'additionalProperties' => true,
            'description' => 'Standard error envelope for disabled feature gates.',
            'example' => [
                'ok' => false,
                'code' => 'CAPABILITY_DISABLED',
                'capability' => 'core.audit.export',
            ],
        ];

        $respComp['CapabilityDisabled'] = [
            'description' => 'Capability is disabled by configuration.',
            'content' => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/CapabilityError'],
                ],
            ],
        ];

        $components['schemas']   = $schemas;
        $components['responses'] = $respComp;
        $doc['components']       = $components;

        /** @var array<string,mixed> $paths */
        $paths = isset($doc['paths']) && is_array($doc['paths']) ? $doc['paths'] : [];
        $paths = $this->attach403ToPaths($paths, '/audit/export.csv', 'get');
        $paths = $this->attach403ToPaths($paths, '/evidence', 'post');
        $doc['paths'] = $paths;

        return $doc;
    }

    /**
     * Safely attach 403 reference for a path+method.
     *
     * @param array<string,mixed> $paths
     * @return array<string,mixed>
     */
    private function attach403ToPaths(array $paths, string $path, string $method): array
    {
        $pathItem = isset($paths[$path]) && is_array($paths[$path]) ? $paths[$path] : null;
        if ($pathItem === null) {
            return $paths;
        }

        $operation = isset($pathItem[$method]) && is_array($pathItem[$method]) ? $pathItem[$method] : null;
        if ($operation === null) {
            return $paths;
        }

        /** @var array<string,mixed> $responses */
        $responses = isset($operation['responses']) && is_array($operation['responses']) ? $operation['responses'] : [];
        $responses['403'] = ['$ref' => '#/components/responses/CapabilityDisabled'];
        $operation['responses'] = $responses;

        $pathItem[$method] = $operation;
        $paths[$path] = $pathItem;

        return $paths;
    }
}

