<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Transfers;

/**
 * Extension point for a Chapa-confirmed signature algorithm. Implementations
 * must authenticate the sender AND bind the transfer details to the signature.
 * Never accept an unsigned request or compare a constant secret-only token.
 */
interface ApprovalSignatureVerifier
{
    public function verify(string $rawBody, ?string $signature): bool;
}
