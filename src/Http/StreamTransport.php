<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Http;

use Nateyk\Chapa\Exceptions\TransportException;

/** Built-in PHP streams transport; redirects and insecure TLS are disabled. */
final class StreamTransport implements Transport
{
    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): RawResponse
    {
        $lines = [];
        foreach ($headers as $key => $value) {
            $lines[] = $key.': '.$value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $lines),
                'content' => $body ?? '',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        // Suppress PHP warnings containing request URLs; never expose secret headers.
        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            throw new TransportException('Chapa HTTP request failed. Check network connectivity and TLS configuration.');
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})/', $line, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }
        if ($status === 0) {
            throw new TransportException('Chapa HTTP response did not contain a valid status line.');
        }

        return new RawResponse($status, $result);
    }
}
