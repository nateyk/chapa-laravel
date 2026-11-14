<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Laravel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Nateyk\Chapa\Transfers\ApprovalDiagnostic;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test-only interoperability probe. Explicit route required. It ALWAYS rejects
 * the transfer and never touches the transfer approval database or Chapa API.
 *
 * Register only to gather non-sensitive signature compatibility evidence from
 * a genuine test-mode callback; REMOVE the route once diagnosis is complete.
 */
final class ChapaTransferApprovalProbeController
{
    public function __invoke(Request $request): Response
    {
        $config = config('chapa.transfer_approval', []);
        $testSecret = config('chapa.secret_key');
        if (($config['probe_enabled'] ?? false) !== true
            || ($config['mode'] ?? null) !== 'test'
            || !is_string($testSecret) || !str_starts_with($testSecret, 'CHAPA_TEST_')) {
            return new Response('', 404);
        }

        $result = ApprovalDiagnostic::inspect(
            $request->getContent(),
            $request->header('Chapa-Signature'),
            is_string($config['secret'] ?? null) ? $config['secret'] : null,
        );

        // Only two fixed status strings; NEVER log body, callback signature,
        // API keys, account name/number, transfer reference, or client IP.
        Log::info('Chapa test transfer approval signature probe', $result);

        // A probe must never authorize a transfer, even for a valid HMAC.
        return new Response('', 400);
    }
}
