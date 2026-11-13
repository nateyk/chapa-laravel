# Security and production integration checklist

**Never treat this SDK alone as the ledger or order state machine.** Perform verification and reconciliation against your database, independently from webhook events.

1. **Keep keys server-side.** Never put the private key in browser JavaScript, logs, public repository content, or queue payloads. The inline API's public key is not a replacement for server-side verification.
2. **Store idempotency keys per operation.** Keep the same key for all retries of the same payment/payout/refund creation. Use a new key for a *new* operation. This SDK requires an explicit key and does not silently retry POST requests.
3. **Validate money and identity.** Use integer cents, merchant reference <= 20 chars, expected currency, and an order-specific reference. On verification, require `data.status === 'SUCCESS'`, exact amount, currency and merchant reference.
4. **Verify raw webhook bytes.** For v2 Signature auth, validate the `x-chapa-signature` HMAC-SHA256 digest of the untouched raw body using a webhook-specific secret and constant-time comparison. Reject unsigned or modified payloads.
5. **Handle webhook delivery duplicates.** After authenticating, create a unique deduplication record from the authenticated raw body hash, webhook configuration, and environment. Apply state transition, balance/order update, and dedup marker **atomically**. Use DB-level uniqueness to make fulfillment once-only even across distinct webhook payloads. For partial refunds use the unique refund reference, not merely the `event` and payment reference.
6. **Verify source-of-truth state.** Webhook payloads contain lower-case event statuses; the verify API uses uppercase data statuses. Always call the verify API before irreversible fulfillment. Reconcile asynchronous payout and refund state separately.
7. **Confirm webhook mode and expected tenant.** Compare `mode`, order ownership, merchant account and reference against the authenticated merchant context. This SDK is not a multi-tenant merchant-account router.
8. **Use webhook-only CSRF exception.** Exempt the specific Laravel webhook route if needed, never all routes. Apply `VerifyChapaWebhook` middleware *before* business logic.
9. **Limit key access and transport risk.** Enforce HTTPS and TLS verification; HTTP redirects are disabled; use short timeouts. Restrict network egress to official Chapa hosts if feasible.
10. **Test-mode verification is required before production.** Validate hosted/direct/inline flows, refunds, payouts, API failure/error codes, webhook replay, and interrupted requests with the actual Chapa sandbox before release. This repo contains no live integration tests or production certification.

## Example raw payload verification (plain PHP)

```php
$raw = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_CHAPA_SIGNATURE'] ?? null;
$event = $chapa->webhooks()->decode($raw, $signature); // throws on invalid signature

// Check mode, ownership, expected reference/amount/currency.
// Verify with Chapa server-side. Persist business state and dedup atomically.
```

Source: <https://docs.chapa.global/docs/v2/integrations/webhooks>.
