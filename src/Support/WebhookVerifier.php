<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Support;

use Nateyk\Chapa\Exceptions\ChapaException;
use Nateyk\Chapa\Webhooks\WebhookEvent;

final class WebhookVerifier
{
    public function __construct(private readonly string $secret)
    {
        if ($this->secret === '') {
            throw new \InvalidArgumentException('A Chapa webhook secret is required.');
        }
    }

    public function verify(string $rawBody, ?string $signature): bool
    {
        if ($signature === null || preg_match('/^[a-f0-9]{64}$/iD', $signature) !== 1) {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $this->secret);

        return hash_equals($expected, strtolower($signature));
    }

    /** @return array<string, mixed> */
    public function decode(string $rawBody, ?string $signature): array
    {
        if (!$this->verify($rawBody, $signature)) {
            throw new ChapaException('Invalid Chapa v2 webhook signature.');
        }

        try {
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ChapaException('Invalid Chapa webhook JSON.', previous: $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ChapaException('Expected a JSON object for Chapa webhook event.');
        }

        return $decoded;
    }

    public function event(string $rawBody, ?string $signature): WebhookEvent
    {
        return WebhookEvent::fromArray($this->decode($rawBody, $signature));
    }

    /** Hash authenticated bytes to build environment-scoped replay protection in your application. */
    public function fingerprint(string $rawBody, string $environment): string
    {
        if (!in_array($environment, ['test', 'live'], true)) {
            throw new \InvalidArgumentException('Webhook environment must be test or live.');
        }

        return hash('sha256', $environment."\0".$rawBody);
    }
}
