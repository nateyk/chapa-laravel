<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Transfers;

/**
 * HMAC over exact raw callback bytes. Chapa's published v1 approval documentation
 * does not specify the HMAC message unambiguously: confirm against a genuine
 * Chapa test callback before enabling live approval; never guess/fallback.
 */
final readonly class ApprovalSignature implements ApprovalSignatureVerifier
{
    public function __construct(private string $approvalSecret)
    {
        if (strlen($approvalSecret) < 16) {
            throw new \InvalidArgumentException('A dedicated strong transfer approval secret is required.');
        }
    }

    public function verify(string $rawBody, ?string $signature): bool
    {
        if (!is_string($signature) || preg_match('/^[a-fA-F0-9]{64}$/D', $signature) !== 1) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $rawBody, $this->approvalSecret), strtolower($signature));
    }
}
