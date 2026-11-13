<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Http;

use Nateyk\Chapa\Exceptions\ApiException;
use Nateyk\Chapa\Exceptions\TransportException;
use Nateyk\Chapa\Support\IdempotencyKey;

final class HttpClient
{
    public function __construct(
        private readonly string $privateKey,
        private readonly ?string $publicKey = null,
        private readonly ?Transport $transport = null,
        private readonly string $baseUrl = 'https://api.chapa.global',
        private readonly int $timeout = 15,
    ) {
        $components = parse_url($baseUrl);
        if ($components === false || ($components['scheme'] ?? null) !== 'https'
            || empty($components['host']) || isset($components['user']) || isset($components['pass'])
            || isset($components['query']) || isset($components['fragment'])
            || isset($components['path']) && !in_array($components['path'], ['', '/'], true)) {
            throw new \InvalidArgumentException('Base URL must be an HTTPS origin without path, credentials, or query.');
        }
        if ($timeout < 1 || $timeout > 120) {
            throw new \InvalidArgumentException('Timeout must be 1–120 seconds.');
        }
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     */
    public function request(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        bool $usePublicKey = false,
        ?string $idempotencyKey = null,
    ): ApiResponse {
        if (!in_array($method, ['GET', 'POST', 'PUT'], true)
            || preg_match('#^/v2/[A-Za-z0-9/_%.-]+$#D', $path) !== 1
            || str_contains($path, '..') || str_contains($path, '//')) {
            throw new \InvalidArgumentException('Unsupported request method or path.');
        }

        $key = $usePublicKey ? $this->publicKey : $this->privateKey;
        if ($key === null || trim($key) === '' || preg_match('/[\r\n]/', $key)) {
            throw new \InvalidArgumentException($usePublicKey ? 'Chapa public key is not configured.' : 'Chapa private key is not configured.');
        }

        $url = rtrim($this->baseUrl, '/').$path;
        if ($query !== []) {
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $headers = [
            'Authorization' => 'Bearer '.$key,
            'Accept' => 'application/json',
            'User-Agent' => 'chapa-laravel/0.x (+https://github.com/nateyk/chapa-laravel)',
        ];
        $jsonBody = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $jsonBody = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = IdempotencyKey::validate($idempotencyKey);
        }

        $result = ($this->transport ?? new StreamTransport())->send($method, $url, $headers, $jsonBody, $this->timeout);
        if ($result->statusCode < 100 || $result->statusCode > 599) {
            throw new TransportException('Chapa HTTP response status was invalid.');
        }

        try {
            $json = json_decode($result->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new TransportException('Chapa returned an invalid JSON response.', previous: $exception);
        }
        if (!is_array($json) || array_is_list($json)) {
            throw new TransportException('Chapa returned an unexpected JSON response shape.');
        }
        if ($result->statusCode >= 400 || ($json['status'] ?? null) === 'error') {
            $code = $json['error']['code'] ?? null;
            $message = $json['message'] ?? 'Chapa API request failed.';
            throw new ApiException(
                $result->statusCode,
                is_string($code) ? $code : null,
                is_string($message) ? $message : 'Chapa API request failed.',
            );
        }
        if ($result->statusCode < 200 || $result->statusCode >= 300) {
            throw new TransportException('Unexpected Chapa HTTP response status.');
        }

        return new ApiResponse($result->statusCode, $json);
    }
}
