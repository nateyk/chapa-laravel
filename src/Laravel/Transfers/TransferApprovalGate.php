<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Laravel\Transfers;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Nateyk\Chapa\Transfers\ApprovalCallback;
use Nateyk\Chapa\Transfers\ApprovalSignature;
use Nateyk\Chapa\Transfers\ApprovalSignatureVerifier;

/** Synchronous server approval: NEVER calls Chapa or changes the payout ledger. */
final class TransferApprovalGate
{
    public function check(string $body, ?string $signature): bool
    {
        if (config('chapa.transfer_approval.enabled', false) !== true) {
            return false;
        }
        $secret = config('chapa.transfer_approval.secret');
        if (!is_string($secret) || strlen($secret) < 16 || strlen($body) > 16384) {
            return false;
        }
        try {
            // Host applications may bind a separately reviewed verifier only after
            // Chapa confirms the exact signing algorithm in test mode.
            $verifier = app()->bound(ApprovalSignatureVerifier::class)
                ? app(ApprovalSignatureVerifier::class)
                : new ApprovalSignature($secret);
            if (!$verifier->verify($body, $signature)) {
                return false;
            }
            $callback = ApprovalCallback::fromRawBody($body);
            $mode = config('chapa.transfer_approval.mode', 'test');
            $integration = config('chapa.transfer_approval.integration', 'default');
            if (!in_array($mode, ['test', 'live'], true)
                || !is_string($integration) || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $integration) !== 1) {
                return false;
            }
            $record = DB::table('chapa_transfer_approvals')
                ->where('integration', $integration)->where('mode', $mode)
                ->where('merchant_reference', $callback->reference)
                ->whereIn('state', ['sending', 'submitted', 'unknown'])
                ->whereNotNull('approved_at')
                ->first();
            if ($record === null || (int) $record->amount_cents !== $callback->amountCents) {
                return false;
            }
            // The older approval callback does not document currency. If absent,
            // ETB only is accepted; USD requires the callback to state its currency.
            if ($callback->currency === null && $record->currency !== 'ETB') {
                return false;
            }
            if ($callback->currency !== null && $record->currency !== $callback->currency) {
                return false;
            }
            $payload = json_decode(Crypt::decryptString($record->payload_encrypted), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || !is_array($payload['account'] ?? null)) {
                return false;
            }
            $account = $payload['account'];
            if (!is_string($account['account_number'] ?? null)
                || !is_string($account['account_name'] ?? null)
                || !is_string($account['bank_slug'] ?? null)) {
                return false;
            }
            // Exact destination comparison, no silent bank-code/slug substitutions.
            return hash_equals(trim($account['account_number']), $callback->accountNumber)
                && hash_equals(trim($account['account_name']), $callback->accountName)
                && hash_equals(trim($account['bank_slug']), $callback->bank);
        } catch (\Throwable) {
            return false; // Fail closed without disclosing signatures or banking details.
        }
    }
}
