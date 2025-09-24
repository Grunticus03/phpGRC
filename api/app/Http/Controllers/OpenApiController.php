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

        return $this->respondWithCaching(
            $request,
            $spec['content'],
            'application/yaml',
            $spec['mtime']
        );
    }

    public function json(Request $request): Response
    {
        $jsonFile = $this->tryLoadSpecJsonFileWithMeta();
        if ($jsonFile !== null) {
            return $this->respondWithCaching(
                $request,
                $jsonFile['content'],
                'application/json',
                $jsonFile['mtime']
            );
        }

        $yaml = $this->loadSpecYamlWithMeta();
        try {
            /** @var array<string,mixed> $data */
            $data = Yaml::parse($yaml['content']);
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

        // Remove any default cache-control directives and set exact value
        if (method_exists($h, 'removeCacheControlDirective')) {
            foreach (['private','public','no-cache','must-revalidate','proxy-revalidate','s-maxage','max-age','no-store','immutable'] as $dir) {
                $h->removeCacheControlDirective($dir);
            }
        }
        if (method_exists($h, 'addCacheControlDirective')) {
            $h->addCacheControlDirective('no-store', true);
            $h->addCacheControlDirective('max-age', '0'); // string to satisfy static analysers
        }
        // Ensure exact header string regardless of framework defaults
        $h->set('Cache-Control', 'no-store, max-age=0');

        return $resp;
    }
}

