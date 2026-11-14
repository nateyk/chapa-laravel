<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Dto;

use Nateyk\Chapa\Http\ApiResponse;

/**
 * Chapa v2 checkout initialization receipt, never proof of payment.
 *
 * Validate a returned redirect URL against known approved checkout hosts.
 * Chapa's documented v2 checkout host is checkout.chapa.global. If Chapa
 * formally moves checkout to another host, explicitly configure its allowlist.
 */
final readonly class HostedCheckoutSession
{
    /** @param list<string> $trustedHosts */
    private function __construct(
        public string $checkoutUrl,
        public string $chapaReference,
        public ?string $createdAt,
        public ?string $expiresAt,
    ) {
    }

    /** @param list<string> $trustedHosts */
    public static function fromResponse(
        ApiResponse $response,
        array $trustedHosts = ['checkout.chapa.global'],
    ): self {
        $data = $response->data();
        if (!$response->operationSucceeded()
            || !is_array($data) || array_is_list($data)
            || !is_string($data['checkout_url'] ?? null)) {
            throw new \UnexpectedValueException('Chapa hosted checkout did not return a valid session.');
        }

        $url = $data['checkout_url'];
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new \UnexpectedValueException('Hosted checkout URL is invalid.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || !is_string($parts['host'] ?? null)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || !in_array(strtolower($parts['host']), $trustedHosts, true)) {
            throw new \UnexpectedValueException('Hosted checkout URL must be HTTPS on a trusted Chapa checkout host.');
        }

        // Official Chapa v2 docs identify the checkout URL's last path
        // segment as the Chapa reference. Never construct a new URL from it.
        $path = $parts['path'] ?? '';
        $segments = explode('/', trim($path, '/'));
        $reference = end($segments);
        if (!is_string($reference) || preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $reference) !== 1) {
            throw new \UnexpectedValueException('Hosted checkout URL has no valid Chapa reference.');
        }

        $created = self::dateField($data, 'created_at');
        $expires = self::dateField($data, 'expires_at');

        return new self($url, $reference, $created, $expires);
    }

    /** @param array<string, mixed> $data */
    private static function dateField(array $data, string $field): ?string
    {
        if (!array_key_exists($field, $data) || $data[$field] === null) {
            return null;
        }
        if (!is_string($data[$field]) ||
            preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $data[$field]) !== 1) {
            throw new \UnexpectedValueException('Hosted checkout timestamp is not ISO-8601: '.$field);
        }
        try {
            new \DateTimeImmutable($data[$field]);
        } catch (\Exception) {
            throw new \UnexpectedValueException('Hosted checkout timestamp is invalid: '.$field);
        }

        return $data[$field];
    }

    /** null if Chapa omitted expires_at; expiration never implies payment failure. */
    public function isExpired(?\DateTimeImmutable $now = null): ?bool
    {
        if ($this->expiresAt === null) {
            return null;
        }

        return new \DateTimeImmutable($this->expiresAt) <= ($now ?? new \DateTimeImmutable('now'));
    }
}
