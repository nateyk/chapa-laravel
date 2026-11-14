# Chapa API v2 payouts — verification and authorization

Sources: [Create Payout](https://docs.chapa.global/docs/v2/payouts/payout), [Verify Payout](https://docs.chapa.global/docs/v2/payouts/verify-payout), [Webhook Overview](https://docs.chapa.global/docs/v2/integrations/webhooks).

## No documented authorization URL

The v2 payout API documents statuses **AUTH_NEEDED** and **OTP_NEEDED**, but does **not** define an \`authorization_url\`, browser redirect, server approval callback, or OTP-submission endpoint in the two referenced endpoints. Do not invent one, or copy the older v1 Transfer Approval URL into v2 without Chapa confirmation.

A successful \`POST /v2/payouts\` returns a **PENDING** creation receipt. Always verify via \`GET /v2/payouts/{reference}/verify\` or reconcile signed webhooks before marking a transfer delivered.

| Status | Handling |
| --- | --- |
| PENDING | Await processing |
| SUCCESS | Verified delivery; also match amount, currency, merchant reference |
| FAILED | Flag failure and review |
| BLOCKED | Flag risk/compliance restrictions |
| AUTH_NEEDED | Additional authorization needed; no documented URL |
| OTP_NEEDED | OTP needed; no documented submission API |
| REVERSED | Previously processed funds reversed; reconcile balances |

## Example SDK usage

\`\`\`php
use Nateyk\Chapa\Dto\PayoutRequest;
use Nateyk\Chapa\Support\IdempotencyKey;

// Persist this key before any payout POST; reuse for retries of this attempt.
$key = IdempotencyKey::generate();
$created = $chapa->payouts()->createTyped(new PayoutRequest(
    amount: 10000,
    currency: 'ETB',
    account: [
        'account_number' => '251911000000',
        'account_name' => 'Abebe Kebede',
        'bank_slug' => 'telebirr',
    ],
    merchantReference: 'payout-101',
    meta: ['batch' => 'OCT-01'],
    reason: 'Vendor payout',
), $key);
$reference = $created->chapaReference; // Store with merchant reference.
// Creation is NOT proof of successful delivery.

$verified = $chapa->payouts()->verifyTyped($reference);
if ($verified->matchesPayout(10000, 'ETB', 'payout-101')) {
    // Atomically mark the payout delivered once.
} elseif ($verified->payoutRequiresAction()) {
    // Alert merchant: AUTH_NEEDED or OTP_NEEDED.
    // Do not guess an authorization URL or ask for OTP in an unsupported API.
}
\`\`\`

For **read-only test-mode** status inspection:

\`\`\`bash
php bin/chapa test --verify-payout=CP_T_17902410738754
\`\`\`

The CLI never submits a payout; it accepts only CHAPA_TEST_ keys.

Persist both merchant and Chapa references; protect all updates with application-level idempotency and state transitions. A valid webhook signature proves origin, **not** final settlement. Match the verification result before updating your business records; reversals can follow success.
