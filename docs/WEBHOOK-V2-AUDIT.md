# Chapa API v2 webhook review and conformance matrix

**Reviewed:** 2026-11-13. **Scope:** Community SDK and Laravel webhook inbox. This is an engineering assessment, not certification from Chapa and not a substitute for live test-mode verification.

Source of truth:

- [Webhook Overview](https://docs.chapa.global/docs/v2/integrations/webhooks)
- [Payment Webhooks](https://docs.chapa.global/docs/v2/webhooks/payment)
- [Payout Webhooks](https://docs.chapa.global/docs/v2/webhooks/payout)
- [Refund Webhooks](https://docs.chapa.global/docs/v2/webhooks/refund)

## Documented events

| `webhook_type` | `event` | Required top-level `status` | Settlement behavior |
| --- | --- | --- | --- |
| payment | `payment.success` | `success` | **Re-verify** with `payments()->verify` before any fulfillment |
| payment | `payment.failed` | `failed` | Reconcile/record failure; never fulfill |
| payment | `payment.cancelled` | `cancelled` | Reconcile/record cancellation; never fulfill |
| payment | `payment.incomplete` | `incomplete` | Reconcile/allow recovery; never fulfill |
| payment | `payment.auth_needed` | `auth_needed` | Await customer authorization; never fulfill |
| payment | `payment.blocked` | `blocked` | Reconcile/record block; never fulfill |
| payout | `payout.success` | `success` | **Verify** before marking delivered |
| payout | `payout.failed` | `failed` | Reconcile; do not initiate another payout automatically |
| payout | `payout.reversed` | `reversed` | Reconcile reversal even if earlier success was processed |
| payout | `payout.blocked` | `blocked` | Reconcile/hold |
| payout | `payout.auth_needed` | `auth_needed` | Await additional authorization |
| payout | `payout.otp_needed` | `otp_needed` | Await OTP; never log OTP values |
| payout | `payout.otp_failed` | `otp_failed` | Report failed authorization attempt |
| refund | `payment.partially_refunded` | `partially_refunded` | Reconcile **separate refund records**; no renewed fulfillment |
| refund | `payment.fully_refunded` | `fully_refunded` | Reconcile **separate refund records**; no renewed fulfillment |

The event names under `refund` deliberately retain the `payment.` prefix. Refund lifecycle notifications identify the original payment; the refund reference returned by **refund creation** must be stored independently and used with `refunds()->verify`. Do not invent a refund reference from the payment reference.

### Authentication and parsing

1. Require the documented v2 `x-chapa-signature` header; do **not** reinterpret a legacy header as equivalent without verifying a supported Chapa contract.
2. Calculate `hash_hmac('sha256', $rawBody, $webhookSecret)` on **exact raw bytes**, then compare using `hash_equals` with the 64-character hex signature.
3. Authenticate **before** JSON parsing, trusting fields, storing or invoking domain actions.
4. Treat top-level webhook `status` as lowercase; verification API `data.status` uses uppercase. Never confuse API envelope `status=success` with settlement success.
5. When `mode` is provided, match it to the integration's configured `test`/`live` environment.
6. Use `amount` and `refunded_amount` as nonnegative integer cents, often encoded as digit strings in notifications. Validate against the merchant's saved order amount and currency.

### Replay, ordering, and concurrency

- Store authenticated raw-body hashes scoped by **integration and mode**. Same signed delivery -> `200 OK` on duplication; different partial refunds must not be collapsed by `payment_reference + event`.
- Use the encrypted Laravel inbox as a durable acknowledgment boundary; process off the request thread. The package's `WebhookInbox` and `WebhookProcessor` already provide deduplication and lease-based workers.
- Multiple deliveries may be out of order. `WebhookStateGuard` returns `apply`, `ignore_duplicate`, `ignore_stale`, or `review` **only when compared against a previously persisted event for the same resource**. If timestamps are absent/ambiguous, return `review` rather than guessing. A payout reversal after payout success is a valid new state.
- Business-level idempotency is different from delivery-level deduplication: use a unique order/fulfillment ledger key and a transaction around local changes, especially if events can be dispatched twice due to worker crashes.
- `WebhookConsistency::assertCurrent` prevents contradictory terminal status notifications from being dispatched when Chapa's most recent verification reports a different terminal status. Failed cases retry and require reconciliation; this is preferable to overwriting a confirmed payment with an older failed event.
- `ChapaWebhookProcessed::paymentMatches` is only an aid; consumers must independently authorize and lock their local order/payment record, check reference/amount/currency and avoid duplicate fulfillment.
- A `200` response means **durable receipt**, not successful payment and not guaranteed listener completion.

## SDK implementation findings

| Audit item | State | Evidence / action |
| --- | --- | --- |
| HMAC of raw bytes, constant-time comparison | Implemented | `src/Support/WebhookVerifier.php` |
| Signature verification before JSON parsing | Implemented | `WebhookVerifier::event`, `WebhookInbox::accept` |
| Strict documented type/name/status mapping | Hardened | `src/Webhooks/WebhookEvent.php` |
| Environment scoping | Implemented | `WebhookInbox::accept`; unknown modes rejected |
| Replay dedup by authenticated raw body scoped to integration/mode | Implemented | `WebhookInbox::accept`, migration unique constraint |
| Receipt encrypted at rest | Implemented | `Crypt::encryptString`, Laravel encryption key required |
| Retry and lease recovery | Implemented | `WebhookProcessor`, `chapa:webhooks:process`, `chapa:webhooks:retry` |
| Payment/payout verification before dispatch | Implemented | `WebhookProcessor::verify` |
| Contradictory terminal state fail closed | Hardened | `WebhookConsistency` in processor |
| Stale timestamp/domain state guard helper | Added | `WebhookStateGuard`; applications still need persisted last-applied state |
| Independent individual refund verification | Application responsibility | Retain refund references at creation; reconcile there |
| Retention/purge policy for encrypted raw receipts | Application responsibility | Configure policy/retention appropriate to local legal and business requirements |
| Real test-mode webhook delivery and webhook retries | Pending | Requires Chapa dashboard, test credentials, reachable endpoint |

## Offline harness (zero network)

```bash
php bin/chapa simulate webhooks
php bin/chapa simulate all --json
php tests/scenarios-smoke.php
```

Synthetic replay checks: correct HMAC, modified-body rejection, duplicate body, environment mismatch, stale payment failure, newer contradictory state needing manual review, two distinct partial refunds, payout reversal after success, and invalid event/status pairing. This is **not** a live webhook endpoint or proof of delivery from Chapa.

## Safe Laravel usage and limitations

See [WEBHOOK-INBOX.md](WEBHOOK-INBOX.md) for installing the migration, routing the webhook, exempting only the specific path from CSRF, scheduling workers and handling retries. Store a durable local `last_verified_at` or state version and compare it transactionally. Never write new payouts/refunds solely in reaction to a webhook. Listener side effects (emails, jobs, external calls) should be dispatched **after DB commit** or through an outbox pattern. Never log raw webhook bodies, secrets, customer details or OTP values.

### Remaining release gates

- Real Chapa test-mode delivery for all documented events; confirm the schema and actual signature header.
- Duplicate and out-of-order events against database-backed business ledger, including independent partial refunds.
- Timeout, retry exhaustion, dead-letter alerting, outage recovery and configured data retention.
- Verify active Chapa dashboard mode matches the stored SDK keys and incoming webhook `mode`.
- Review finance reconciliation, PII retention and production use separately before tagging a stable release.
