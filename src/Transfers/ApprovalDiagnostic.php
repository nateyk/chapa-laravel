<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Transfers;

/**
 * Redacted diagnostic for the unconfirmed Chapa transfer approval signature
 * protocol. Never logs or returns the body, signature, account, or secret.
 *
 * A valid local HMAC demonstrates compatibility with this verifier, not that
 * Chapa v2 will invoke the legacy approval callback in the first place.
 */
final class ApprovalDiagnostic
{
    /** @return array{signature: string, payload: string} */
    public static function inspect(string $rawBody, ?string $signature, ?string $secret): array
    {
        if ($rawBody === '' || strlen($rawBody) > 16384) {
            return ['signature' => 'not_checked', 'payload' => 'rejected_size'];
        }
        if (!is_string($secret) || strlen($secret) < 16) {
            return ['signature' => 'not_checked', 'payload' => 'unconfigured'];
        }
        try {
            if (!(new ApprovalSignature($secret))->verify($rawBody, $signature)) {
                return ['signature' => 'mismatch', 'payload' => 'not_checked'];
            }
            ApprovalCallback::fromRawBody($rawBody);
            return ['signature' => 'matched_raw_body_hmac', 'payload' => 'valid'];
        } catch (\Throwable) {
            return ['signature' => 'matched_raw_body_hmac', 'payload' => 'invalid'];
        }
    }
}
